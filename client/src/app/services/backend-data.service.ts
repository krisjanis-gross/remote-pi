import { Injectable } from '@angular/core';
import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';

import { Observable, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';

import { ApiError, ApiResponse, DeviceConnection } from '../models/device';
import { DeviceConfig, HistoricSeries, Period, Relay, SensorData, SensorListItem, Trigger, VersionInfo } from '../models/api';
import { checkApiCompatibility } from '../models/api-version';

/** ApiError.status when the device answers but its API version does not fit this app. */
export const API_INCOMPATIBLE = -2;

const API_PATH = '/json_API_v2.php';

/**
 * Client for a device's json_API_v2.php.
 *
 * Stateless: every call names the device it is for, so pages that talk to several
 * devices (the device list pings all of them) never send one device's key to another.
 * Calls fail with an {@link ApiError} both on HTTP errors and when the device answers
 * with response_code "ERROR".
 */
@Injectable({
  providedIn: 'root'
})
export class BackendDataService {

  constructor(private http: HttpClient) { }

  checkDeviceVersion(device: DeviceConnection): Observable<ApiResponse<VersionInfo>> {
    return this.call(device, 'version_check');
  }

  /**
   * version_check plus the app's version check: emits the device's API version, or fails
   * with an ApiError (status API_INCOMPATIBLE) that says whether to update the device or the app.
   */
  connect(device: DeviceConnection): Observable<string> {
    return this.checkDeviceVersion(device).pipe(
      map(response => {
        const compatibility = checkApiCompatibility(response.response_data?.version);
        if (!compatibility.ok) {
          throw new ApiError(compatibility.message, API_INCOMPATIBLE);
        }
        return compatibility.version;
      }),
    );
  }

  getSensorData(device: DeviceConnection): Observable<ApiResponse<SensorData>> {
    return this.call(device, 'get_sensor_data');
  }

  getSensorDataAndSparkline(device: DeviceConnection): Observable<ApiResponse<SensorData>> {
    return this.call(device, 'get_sensor_data_sparkline');
  }

  getRelayData(device: DeviceConnection): Observable<ApiResponse<Relay[]>> {
    return this.call(device, 'get_GPIO_list');
  }

  setRelayState(device: DeviceConnection, relayID: string | number, newState: number): Observable<ApiResponse> {
    return this.call(device, 'set_GPIO_pin', { pin_id: relayID, command: newState });
  }

  getTriggerData(device: DeviceConnection): Observable<ApiResponse<Trigger[]>> {
    return this.call(device, 'get_Trigger_list');
  }

  toggleTriggerState(device: DeviceConnection, triggerID: string | number, newState: number): Observable<ApiResponse> {
    return this.call(device, 'set_trigger_state', { triggerID, command: newState });
  }

  setParameterValue(device: DeviceConnection, parameterID: string | number, newValue: string | number): Observable<ApiResponse> {
    return this.call(device, 'setParameterValue', { parameterID, newValue });
  }

  setSensorName(device: DeviceConnection, sensorID: string, sensorName: string): Observable<ApiResponse> {
    return this.call(device, 'setSensorName', { sensorID, sensorName });
  }

  setPinConfig(device: DeviceConnection, pinID: string | number, newPinId: string | number, pinDescription: string): Observable<ApiResponse> {
    return this.call(device, 'setPinConfig', { pinID, newPinId, pinDescription });
  }

  getDeviceConfig(device: DeviceConnection): Observable<ApiResponse<DeviceConfig>> {
    return this.call(device, 'getDeviceConfig');
  }

  getChartData(device: DeviceConnection, period: Period, selectedSensors: (string | number)[] | ''): Observable<ApiResponse<HistoricSeries[]>> {
    return this.call(device, 'get_historic_data', { period, selected_sensors: selectedSensors });
  }

  getSensorList(device: DeviceConnection, period: Period): Observable<ApiResponse<SensorListItem[]>> {
    return this.call(device, 'get_sensor_list', { period });
  }

  restartDevice(device: DeviceConnection): Observable<ApiResponse> {
    return this.call(device, 'restart_device');
  }

  shutdownDevice(device: DeviceConnection): Observable<ApiResponse> {
    return this.call(device, 'shutdown_device');
  }

  restoreDeviceConfiguration(device: DeviceConnection): Observable<ApiResponse> {
    return this.call(device, 'restore_device_configuration');
  }

  backupDeviceConfiguration(device: DeviceConnection): Observable<ApiResponse> {
    return this.call(device, 'backup_device_configuration');
  }

  private call<T = any>(device: DeviceConnection, action: string, data: unknown = ''): Observable<ApiResponse<T>> {
    const url = (device.deviceURL ?? '').replace(/\/+$/, '') + API_PATH;
    // Devices from API 0.5 read the key from the header; older ones only from the body,
    // so the body keeps it until API 1.0.
    const headers = new HttpHeaders({
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-API-Key': device.deviceKEY ?? '',
    });
    const body = { API_key: device.deviceKEY, request_action: action, request_data: data };

    return this.http.post<ApiResponse<T>>(url, body, { headers }).pipe(
      map(response => {
        if (response?.response_code !== 'OK') {
          throw new ApiError(errorText(response?.response_data) ?? 'The device reported an error', 200);
        }
        return response;
      }),
      catchError(error => throwError(() => toApiError(error))),
    );
  }
}

function errorText(data: unknown): string | null {
  return typeof data === 'string' && data !== '' ? data : null;
}

function toApiError(error: unknown): ApiError {
  if (error instanceof ApiError) {
    return error;
  }
  if (error instanceof HttpErrorResponse) {
    if (error.status === 0) {
      return new ApiError('Device not reachable', 0);
    }
    // The device answers errors with the same envelope, e.g. 403 "API key not correct".
    const message = errorText(error.error?.response_data) ?? `Request failed (HTTP ${error.status})`;
    return new ApiError(message, error.status);
  }
  return new ApiError('Unexpected error', -1);
}
