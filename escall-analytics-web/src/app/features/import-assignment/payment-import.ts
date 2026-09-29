import { ChangeDetectionStrategy, Component, OnDestroy, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Subscription, exhaustMap, timer } from 'rxjs';
import { FeedbackService } from '../../core/services/feedback.service';
import {
  ImportApiService,
  ImportConfiguration,
  ImportRecord,
  ImportStatus,
  ImportStatusRecord,
} from '../../core/services/import-api.service';
import { Icon } from '../../shared/components/icon/icon';

@Component({
  selector: 'app-payment-import',
  imports: [FormsModule, Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="payment-import-layout">
      <section class="detail-panel import-workspace">
        <header class="detail-header">
          <div>
            <h2>Nueva importación</h2>
            <p>El archivo se procesará automáticamente al iniciar la importación.</p>
          </div>
          @if (config()) {
            <span class="import-limit"
              >Hasta {{ number(config()!.maxRows) }} filas · {{ config()!.maxFileMb }} MB</span
            >
          }
        </header>

        <div class="import-fields">
          <label>
            Periodo
            <div class="locked-field">
              <app-icon name="CalendarDays" [size]="14" />Desde FECHA_PAGO
            </div>
            <small>Se detecta automáticamente por cada fila</small>
          </label>
          <label>
            Tipo
            <div class="locked-field"><app-icon name="WalletCards" [size]="14" />Pagos</div>
            <small>Disponible actualmente</small>
          </label>
          <div class="import-current">
            <span>Importación acumulativa</span>
            <strong>Pagos Expertis</strong>
            <small>Los pagos válidos se agregan sin reemplazar periodos.</small>
          </div>
        </div>

        <input
          #fileInput
          class="import-file-input"
          id="payment-file"
          type="file"
          accept=".csv,.txt,text/csv,text/plain"
          (change)="select($event)"
        />
        <label
          for="payment-file"
          class="import-dropzone"
          [class.dragging]="dragging()"
          [class.has-file]="file()"
          (dragover)="dragOver($event)"
          (dragleave)="dragging.set(false)"
          (drop)="drop($event)"
        >
          <app-icon [name]="file() ? 'FileCheck2' : 'UploadCloud'" [size]="25" />
          @if (file()) {
            <div>
              <strong>{{ file()!.name }}</strong
              ><span
                >{{ fileSize(file()!.size) }} ·
                {{ file()!.name.split('.').pop()?.toUpperCase() }}</span
              >
            </div>
            <button
              type="button"
              class="dropzone-remove"
              (click)="clearFile($event)"
              aria-label="Quitar archivo"
            >
              <app-icon name="X" [size]="15" />
            </button>
          } @else {
            <div><strong>Arrastra tu archivo aquí</strong><span>o selecciona un archivo</span></div>
            <small>Formatos permitidos: CSV y TXT</small>
          }
        </label>

        <div class="import-actions">
          <button
            class="btn btn-primary"
            type="button"
            (click)="importFile()"
            [disabled]="busy() || !file()"
          >
            @if (uploading()) {
              <app-icon name="LoaderCircle" [size]="15" />Subiendo...
            } @else {
              <app-icon name="ArrowDownToLine" [size]="15" />Importar pagos
            }
          </button>
          @if (current()) {
            <span class="import-status-text"
              ><span
                class="status-dot"
                [class.failed]="current()!.status === 'failed'"
                [class.done]="current()!.status === 'completed'"
              ></span
              >{{ label(current()!.status) }}</span
            >
          }
          <button class="btn btn-secondary" type="button" (click)="toggleManual()">
            <app-icon name="Wallet" [size]="15" />Registrar pago individual
          </button>
          <button class="btn btn-secondary" type="button" (click)="template()">
            <app-icon name="ArrowDownToLine" [size]="15" />Descargar plantilla CSV
          </button>
        </div>

        @if (manualOpen()) {
          <section
            class="import-progress-card manual-payment-form"
            aria-label="Registrar pago individual"
          >
            <header>
              <div>
                <h3>Registrar pago individual</h3>
                <p>El registro se valida contra la asignación del período antes de importarse.</p>
              </div>
              <button type="button" class="table-link" (click)="toggleManual()">Cerrar</button>
            </header>
            <div class="import-fields manual-fields">
              <label
                >Fecha de pago<input type="date" [(ngModel)]="manual.fechaPago" [disabled]="busy()"
              /></label>
              <label
                >Código<input
                  type="text"
                  [(ngModel)]="manual.codigo"
                  placeholder="27834618-TRUJILLO"
                  [disabled]="busy()"
              /></label>
              <label
                >Monto<input
                  type="number"
                  min="0.01"
                  step="0.01"
                  [(ngModel)]="manual.monto"
                  placeholder="0.00"
                  [disabled]="busy()"
              /></label>
              <label
                >Ejecutivo<input
                  type="text"
                  [(ngModel)]="manual.ejecutivo"
                  placeholder="Nombre del ejecutivo"
                  [disabled]="busy()"
              /></label>
              <label
                >Tipo de acuerdo<select [(ngModel)]="manual.tipoAcuerdo" [disabled]="busy()">
                  <option value="PPC">PPC</option>
                  <option value="PPM">PPM</option>
                  <option value="PAR">PAR</option>
                </select></label
              >
            </div>
            <footer>
              <button
                class="btn btn-primary"
                type="button"
                (click)="importManual()"
                [disabled]="busy()"
              >
                <app-icon name="ArrowDownToLine" [size]="15" />Importar pago
              </button>
            </footer>
          </section>
        }

        @if (current()) {
          <section
            class="import-result"
            [class.failed]="current()!.status === 'failed'"
            [class.completed]="isCompleted(current()!.status)"
          >
            <app-icon
              [name]="
                current()!.status === 'failed'
                  ? 'TriangleAlert'
                  : isCompleted(current()!.status)
                    ? 'CheckCheck'
                    : 'LoaderCircle'
              "
              [size]="19"
              [class.spin]="inProgress(current()!.status)"
            />
            <div>
              <h3>{{ resultTitle(current()!.status) }}</h3>
              <p>{{ resultDescription(current()!) }}</p>
            </div>
            @if (current()!.hasErrorsFile) {
              <button class="btn btn-secondary" type="button" (click)="downloadErrors(current()!)">
                <app-icon name="ArrowDownToLine" [size]="15" />Descargar errores
              </button>
            }
          </section>
          @if (current()!.error) {
            <p class="form-error">{{ current()!.error }}</p>
          }
        }
      </section>

      <section class="detail-panel import-history">
        <header class="detail-header">
          <div>
            <h2>Historial de importaciones</h2>
            <p>{{ history().length }} registros recientes</p>
          </div>
          <button class="btn btn-secondary" type="button" (click)="loadHistory()">
            <app-icon name="RefreshCw" [size]="14" />Actualizar
          </button>
        </header>
        <div class="users-table-wrap">
          <table class="compact-table import-history-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Periodo</th>
                <th>Archivo</th>
                <th>Filas</th>
                <th>Estado</th>
                <th>Usuario</th>
                <th>Duración</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              @for (item of history(); track item.id) {
                <tr>
                  <td>{{ dateTime(item.createdAt) }}</td>
                  <td>Pagos</td>
                  <td>Varios períodos</td>
                  <td class="file-name-cell" [title]="item.originalFilename">
                    {{ item.originalFilename }}
                  </td>
                  <td>{{ number(item.rowsInserted || item.rowsTotal) }}</td>
                  <td>
                    <span class="import-status-chip" [attr.data-status]="item.status">{{
                      label(item.status)
                    }}</span>
                  </td>
                  <td>{{ item.createdBy ?? '-' }}</td>
                  <td>{{ duration(item.durationSeconds) }}</td>
                  <td>
                    <button type="button" class="table-link" (click)="showDetail(item)">
                      Ver detalle
                    </button>
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="9" class="table-empty">Aún no existen importaciones de pagos.</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </section>
    </div>
  `,
  styles: [
    `
      .payment-import-layout {
        display: flex;
        flex-direction: column;
        gap: 10px;
        width: 100%;
      }
      .manual-payment-form {
        margin: 0 13px 13px;
        padding: 11px 12px;
        background: #fbfdff;
      }
      .manual-payment-form header {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
      }
      .manual-payment-form h3 {
        margin: 0;
        color: #24476e;
        font-size: 11px;
      }
      .manual-payment-form p {
        margin: 3px 0 0;
        color: #7c8ba0;
        font-size: 9px;
      }
      .manual-fields {
        grid-template-columns: repeat(5, minmax(130px, 1fr));
        gap: 10px;
        margin-top: 12px;
        padding: 0;
        border: 0;
      }
      .manual-fields input,
      .manual-fields select {
        width: 100%;
        box-sizing: border-box;
        height: 34px;
        border: 1px solid #d6e1ef;
        border-radius: 7px;
        background: #fff;
        color: #24476e;
        padding: 0 10px;
        font-size: 12px;
      }
      .manual-payment-form footer {
        margin-top: 12px;
      }
      @media (max-width: 1100px) {
        .manual-fields {
          grid-template-columns: repeat(3, minmax(160px, 1fr));
        }
      }
      @media (max-width: 650px) {
        .manual-fields {
          grid-template-columns: 1fr;
        }
        .manual-payment-form header {
          flex-direction: column;
        }
      }
    `,
  ],
})
export class PaymentImportComponent implements OnDestroy {
  private readonly api = inject(ImportApiService);
  private readonly feedback = inject(FeedbackService);
  private poller?: Subscription;

  readonly config = signal<ImportConfiguration | null>(null);
  readonly file = signal<File | null>(null);
  readonly current = signal<ImportRecord | null>(null);
  readonly history = signal<ImportRecord[]>([]);
  readonly dragging = signal(false);
  readonly uploading = signal(false);
  readonly manualOpen = signal(false);
  readonly manual = {
    fechaPago: new Date().toISOString().slice(0, 10),
    codigo: '',
    monto: '',
    ejecutivo: '',
    tipoAcuerdo: 'PPC',
  };

  constructor() {
    this.api.paymentConfiguration().subscribe({ next: (value) => this.config.set(value) });
    this.loadHistory();
  }

  ngOnDestroy(): void {
    this.poller?.unsubscribe();
  }
  busy(): boolean {
    return this.uploading() || this.inProgress(this.current()?.status);
  }
  select(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.setFile(input.files?.item(0) ?? null);
    input.value = '';
  }
  dragOver(event: DragEvent): void {
    event.preventDefault();
    this.dragging.set(true);
  }
  drop(event: DragEvent): void {
    event.preventDefault();
    this.dragging.set(false);
    this.setFile(event.dataTransfer?.files.item(0) ?? null);
  }
  clearFile(event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    this.file.set(null);
    this.current.set(null);
    this.poller?.unsubscribe();
  }
  toggleManual(): void {
    this.manualOpen.update((value) => !value);
  }

  importFile(): void {
    const file = this.file();
    if (!file) return;
    this.uploading.set(true);
    this.api.importPayments(file).subscribe({
      next: (item) => {
        this.uploading.set(false);
        this.current.set(item);
        this.poll(item.id);
      },
      error: (error) => {
        this.uploading.set(false);
        this.feedback.show(error.error?.message ?? 'No se pudo recibir el archivo.');
      },
    });
  }
  importManual(): void {
    const { fechaPago, codigo, monto, ejecutivo, tipoAcuerdo } = this.manual;
    if (!fechaPago || !codigo.trim() || !monto || Number(monto) <= 0 || !ejecutivo.trim()) {
      this.feedback.show('Completa fecha, código, monto, ejecutivo y tipo de acuerdo.');
      return;
    }
    const escape = (value: string): string => `"${value.replaceAll('"', '""')}"`;
    const csv = `FECHA_PAGO;CODIGO;MONTO;EJECUTIVO;TIPO_ACUERDO\n${escape(fechaPago)};${escape(codigo)};${escape(Number(monto).toFixed(2))};${escape(ejecutivo)};${escape(tipoAcuerdo)}\n`;
    const file = new File([csv], 'pago-individual.csv', { type: 'text/csv' });
    this.file.set(file);
    this.uploading.set(true);
    this.api.importPayments(file).subscribe({
      next: (item) => {
        this.uploading.set(false);
        this.current.set(item);
        this.manualOpen.set(false);
        this.poll(item.id);
      },
      error: (error) => {
        this.uploading.set(false);
        this.feedback.show(error.error?.message ?? 'No se pudo importar el pago.');
      },
    });
  }
  template(): void {
    this.api.paymentTemplate().subscribe({
      next: (file) => {
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'plantilla-pagos-expertis.csv';
        link.click();
        URL.revokeObjectURL(url);
      },
      error: () => this.feedback.show('No se pudo descargar la plantilla CSV.'),
    });
  }
  showDetail(item: ImportRecord): void {
    this.current.set(item);
    if (this.inProgress(item.status)) this.poll(item.id);
  }

  label(status: ImportStatus): string {
    return {
      uploaded: 'Archivo recibido',
      validating: 'Procesando',
      validated: 'Procesando',
      processing: 'Procesando',
      completed: 'Completado',
      completed_with_errors: 'Completado con errores',
      failed: 'Fallido',
      cancelled: 'Cancelado',
    }[status];
  }
  resultTitle(status: ImportStatus): string {
    if (this.inProgress(status) || status === 'validated') return 'Procesando archivo...';
    if (status === 'completed_with_errors') return 'Importación completada con errores';
    if (status === 'completed') return 'Importación completada';
    return 'No se pudo importar el archivo';
  }
  resultDescription(item: ImportRecord): string {
    if (this.inProgress(item.status) || item.status === 'validated')
      return 'El archivo se está procesando automáticamente.';
    if (item.status === 'completed_with_errors')
      return `${this.number(item.rowsInserted)} filas fueron importadas correctamente y ${this.number(item.rowsRejected)} filas presentaron errores.`;
    if (item.status === 'completed')
      return `${this.number(item.rowsInserted)} filas fueron importadas correctamente.`;
    return item.error ?? 'Verifica la estructura del archivo e inténtalo nuevamente.';
  }
  isCompleted(status: ImportStatus): boolean {
    return status === 'completed' || status === 'completed_with_errors';
  }
  number(value: number | null | undefined): string {
    return new Intl.NumberFormat('es-PE').format(value ?? 0);
  }
  fileSize(value: number): string {
    return value >= 1048576
      ? `${(value / 1048576).toFixed(1)} MB`
      : `${Math.max(1, Math.round(value / 1024))} KB`;
  }
  dateTime(value: string | null): string {
    return value
      ? new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'short' }).format(
          new Date(value),
        )
      : '-';
  }
  duration(seconds: number | null): string {
    if (seconds === null) return '-';
    const total = Math.max(0, Math.round(seconds));
    return total < 60 ? `${total}s` : `${Math.floor(total / 60)}m ${total % 60}s`;
  }

  private setFile(file: File | null): void {
    if (!file) return;
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!['csv', 'txt'].includes(extension)) {
      this.feedback.show('Formato no permitido. Utiliza un archivo CSV o TXT.');
      return;
    }
    if (this.config() && file.size > this.config()!.maxFileMb * 1048576) {
      this.feedback.show(`El archivo excede el límite de ${this.config()!.maxFileMb} MB.`);
      return;
    }
    this.file.set(file);
    this.current.set(null);
    this.poller?.unsubscribe();
  }
  private poll(id: number): void {
    this.poller?.unsubscribe();
    this.poller = timer(0, 5000)
      .pipe(exhaustMap(() => this.api.status(id)))
      .subscribe({
        next: (status) => {
          this.apply(status);
          if (!this.inProgress(status.status)) {
            this.poller?.unsubscribe();
            this.loadHistory();
            const current = this.current();
            if (current && this.isCompleted(status.status))
              this.feedback.show(this.resultDescription(current));
          }
        },
        error: () => {
          this.poller?.unsubscribe();
          this.feedback.show('No se pudo consultar el estado de la importación.');
        },
      });
  }
  loadHistory(): void {
    this.api
      .history('expertis_pagos')
      .subscribe({ next: (result) => this.history.set(result.data) });
  }
  private apply(status: ImportStatusRecord): void {
    const item = this.current();
    if (item?.id === status.id) this.current.set({ ...item, ...status });
  }
  inProgress(status: ImportStatus | undefined): boolean {
    return (
      status === 'uploaded' ||
      status === 'validating' ||
      status === 'validated' ||
      status === 'processing'
    );
  }
  downloadErrors(item: ImportRecord): void {
    this.api.downloadErrors(item.id).subscribe({
      next: (file) => {
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = `errores-pagos-${item.id}.csv`;
        link.click();
        URL.revokeObjectURL(url);
      },
      error: () => this.feedback.show('No se pudo descargar el archivo de errores.'),
    });
  }
}
