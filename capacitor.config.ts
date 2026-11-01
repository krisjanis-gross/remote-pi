import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  plugins: {
      CapacitorHttp: {
        enabled: true,
      },
    },
  appId: 'com.rpiv30',
  appName: 'remote-pi-ionic-v7',
  webDir: 'www'
};

export default config;
