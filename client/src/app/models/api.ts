// Response types of the device API. The contract is api/json_API_v2.schema.json; the
// shapes here follow it, and api-contract.spec.ts checks the requests the app sends.

/** [time in ms (local time written as UTC), value] */
export type ChartPoint = [number, number];

export interface VersionInfo {
  version: string;
}

export interface SensorReading {
  id: string | number;
  sensor_name: string | number;
  value: number | string;
  /** Only from get_sensor_data_sparkline: the last 10 minutes. */
  sparkline_data?: ChartPoint[];
}

export interface SensorData {
  timestamp: string | null;
  data: SensorReading[];
}

export interface Relay {
  id: number;
  state: 0 | 1;
  locked: 0 | 1;
  description: string | null;
}

export interface TriggerParameter {
  id: number;
  name: string | null;
  par_value: number | string | null;
}

export interface Trigger {
  triggerID: number;
  state: 0 | 1;
  description: string | null;
  parameters: TriggerParameter[];
}

export type DeviceConfig = Record<string, number | string | null>;

export interface SensorListItem {
  id: string | number;
  name: string | number;
}

export interface HistoricSeries {
  id: string | number;
  name: string | number;
  data: ChartPoint[];
  type: 'spline';
}

export type Period = 'hour' | '3hrs' | '6hrs' | 'day' | '3days' | 'week' | 'month';
