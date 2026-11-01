# shellcheck shell=bash
# Inbound: SSH and the web app from LAN_CIDRS only (plus ALLOW_INTERFACES such as a VPN).

http_ports="80"
for port in $EXTRA_HTTP_PORTS; do http_ports+=", $port"; done

lan4="" lan6=""
for cidr in $LAN_CIDRS; do
	if [[ "$cidr" == *:* ]]; then lan6+="${lan6:+, }$cidr"; else lan4+="${lan4:+, }$cidr"; fi
done
[[ -n "$lan4$lan6" ]] || die "LAN_CIDRS is empty"

# Do not cut off open SSH sessions (sudo drops SSH_CLIENT, so look at the sockets).
ssh_peers=$(ss -Htn state established '( sport = :22 )' | awk '{print $4}' | sed -E 's/^\[?(.*)\]?:[0-9]+$/\1/; s/\]$//' | sort -u | xargs)
if [[ -n "$ssh_peers" && -z "$ALLOW_INTERFACES" ]]; then
	# shellcheck disable=SC2086 # one argument per CIDR
	python3 - "$ssh_peers" $LAN_CIDRS <<'EOF' || die "an SSH session comes from outside LAN_CIDRS ($LAN_CIDRS: peers $ssh_peers); you would be locked out"
import ipaddress, sys
networks = [ipaddress.ip_network(c, strict=False) for c in sys.argv[2:]]
for peer in sys.argv[1].split():
    ip = ipaddress.ip_address(peer)
    if ip.version == 6 and ip.ipv4_mapped:
        ip = ip.ipv4_mapped
    if not (ip.is_loopback or ip.is_link_local or any(ip in n for n in networks)):
        sys.exit(1)
EOF
fi

rules=""
[[ -n "$lan4" ]] && rules+="        ip saddr { $lan4 } tcp dport { 22, $http_ports } accept"$'\n'
[[ -n "$lan6" ]] && rules+="        ip6 saddr { $lan6 } tcp dport { 22, $http_ports } accept"$'\n'
rules+="        ip6 saddr fe80::/10 tcp dport { 22, $http_ports } accept"$'\n'
for iface in $ALLOW_INTERFACES; do
	rules+="        iifname \"$iface\" tcp dport { 22, $http_ports } accept"$'\n'
done

tmp_rules=$(mktemp)
cat >"$tmp_rules" <<EOF
#!/usr/sbin/nft -f
# Managed by remote-pi provision/install.sh
flush ruleset

table inet filter {
    chain input {
        type filter hook input priority filter; policy drop;
        iif lo accept
        ct state established,related accept
        ct state invalid drop
        meta l4proto { icmp, ipv6-icmp } accept
        udp dport 5353 accept comment "mDNS (hostname.local)"
        udp sport 67 udp dport 68 accept comment "DHCP replies"
$rules    }
    chain forward {
        type filter hook forward priority filter; policy drop;
    }
    chain output {
        type filter hook output priority filter; policy accept;
    }
}
EOF
nft -c -f "$tmp_rules"
install -o root -g root -m 0755 "$tmp_rules" /etc/nftables.conf
rm -f "$tmp_rules"
systemctl enable nftables
systemctl restart nftables
