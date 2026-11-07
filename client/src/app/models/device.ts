/** A Remote Pi device saved in the app's device list. */
export interface Device {
  deviceID: number;
  deviceTitle: string;
  deviceURL: string;
  deviceKEY: string;
}

/** What the API needs to reach a device: its base URL and API key. */
export type DeviceConnection = Pick<Device, 'deviceURL' | 'deviceKEY'>;

/** Provisioned (hardened) devices reject API keys shorter than this. */
export const MIN_API_KEY_LENGTH = 16;

/** Every json_API_v2.php response is wrapped in this envelope. */
export interface ApiResponse<T = any> {
  response_code: 'OK' | 'ERROR';
  response_data: T;
}

/** A failed API call: the device's error message, or why it could not be reached. */
export class ApiError extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
    this.name = 'ApiError';
  }
}
