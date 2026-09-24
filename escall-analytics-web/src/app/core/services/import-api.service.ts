import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export type ImportStatus = 'uploaded' | 'validating' | 'validated' | 'processing' | 'completed' | 'failed' | 'cancelled';

export interface ImportError {
  row: number | null;
  column: string;
  message: string;
}

export interface ImportConfiguration {
  maxFileMb: number;
  maxRows: number;
  chunkSize: number;
  allowedExtensions: string[];
  period?: string;
  existingRows?: number;
}

export interface ImportRecord {
  id: number;
  type: string;
  period: string;
  originalFilename: string;
  fileSize: number;
  fileSha256: string;
  status: ImportStatus;
  progress: number;
  rowsTotal: number | null;
  rowsProcessed: number;
  rowsInserted: number;
  rowsRejected: number;
  clients: number;
  accounts: number;
  duplicates: number;
  errors: ImportError[];
  error: string | null;
  existingRows: number | null;
  createdBy: string | null;
  createdAt: string | null;
  startedAt: string | null;
  finishedAt: string | null;
  durationSeconds: number | null;
  metrics: Record<string, number>;
  validAmount?: number;
  byAgreement?: Record<string, number>;
}

export type ImportStatusRecord = Pick<
  ImportRecord,
  | 'id'
  | 'status'
  | 'progress'
  | 'rowsTotal'
  | 'rowsProcessed'
  | 'rowsInserted'
  | 'rowsRejected'
  | 'startedAt'
  | 'finishedAt'
  | 'error'
>;

@Injectable({ providedIn: 'root' })
export class ImportApiService {
  private readonly http = inject(HttpClient);
  private readonly assignmentUrl = `${environment.apiBaseUrl}/importaciones/expertis/asignacion`;
  private readonly paymentsUrl = `${environment.apiBaseUrl}/importaciones/expertis/pagos`;
  private readonly importsUrl = `${environment.apiBaseUrl}/imports`;

  configuration(period: string): Observable<ImportConfiguration> {
    return this.http.get<ImportConfiguration>(`${this.importsUrl}/config`, { params: { period } });
  }

  validate(period: string, file: File): Observable<ImportRecord> {
    const data = new FormData();
    data.set('period', period);
    data.set('file', file);

    return this.http.post<ImportRecord>(`${this.assignmentUrl}/validar`, data);
  }

  import(importId: number): Observable<ImportRecord> {
    return this.http.post<ImportRecord>(`${this.assignmentUrl}/importar`, { importId });
  }

  status(importId: number): Observable<ImportStatusRecord> {
    return this.http.get<ImportStatusRecord>(`${this.importsUrl}/${importId}`);
  }

  template(): Observable<Blob> {
    return this.http.get(`${this.assignmentUrl}/plantilla`, { responseType: 'blob' });
  }

  history(type?: string): Observable<{ data: ImportRecord[] }> {
    return this.http.get<{ data: ImportRecord[] }>(`${environment.apiBaseUrl}/importaciones`, { params: type ? { type } : {} });
  }

  paymentConfiguration(): Observable<ImportConfiguration> {
    return this.http.get<ImportConfiguration>(`${this.importsUrl}/pagos/config`);
  }

  validatePayments(file: File): Observable<ImportRecord> {
    const data = new FormData();
    data.set('file', file);
    return this.http.post<ImportRecord>(`${this.paymentsUrl}/validar`, data);
  }

  importPayments(importId: number): Observable<ImportRecord> {
    return this.http.post<ImportRecord>(`${this.paymentsUrl}/importar`, { importId });
  }

  paymentTemplate(): Observable<Blob> {
    return this.http.get(`${this.paymentsUrl}/plantilla`, { responseType: 'blob' });
  }
}
