import { Storage } from '@ionic/storage-angular';

/** In-memory stand-in for Ionic Storage, so tests need no IndexedDB. */
export class FakeStorage {
  readonly data = new Map<string, unknown>();

  async create(): Promise<this> {
    return this;
  }

  async get(key: string): Promise<any> {
    return this.data.get(key);
  }

  async set(key: string, value: unknown): Promise<unknown> {
    this.data.set(key, value);
    return value;
  }
}

export function provideFakeStorage(storage = new FakeStorage()) {
  return [{ provide: Storage, useValue: storage }];
}
