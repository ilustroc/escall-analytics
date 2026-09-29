import { ChangeDetectionStrategy, Component, OnDestroy, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Subscription, exhaustMap, timer } from 'rxjs';
import { FeedbackService } from '../../core/services/feedback.service';
import {
  ImportConfiguration,
  ImportRecord,
  ImportStatusRecord,
  ImportStatus,
  ImportApiService,
} from '../../core/services/import-api.service';
import { Icon } from '../../shared/components/icon/icon';
import { PaymentImportComponent } from './payment-import';

type ImportTab = 'asignacion' | 'pagos' | 'gestiones';

@Component({
  selector: 'app-import-assignment',
  imports: [FormsModule, Icon, PaymentImportComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<section class="dashboard-page import-page">
    <div class="page-header import-page-header">
      <div>
        <h1>Importaciones Expertis</h1>
        <p>Administración de archivos y fuentes recibidas de Expertis.</p>
      </div>
    </div>

    <nav class="import-tabs" aria-label="Tipos de importación">
      <button type="button" [class.active]="tab() === 'asignacion'" (click)="tab.set('asignacion')">
        <app-icon name="FileSpreadsheet" [size]="15" />Asignación
      </button>
      <button type="button" [class.active]="tab() === 'pagos'" (click)="tab.set('pagos')">
        <app-icon name="WalletCards" [size]="15" />Pagos
      </button>
      <button type="button" [class.active]="tab() === 'gestiones'" (click)="tab.set('gestiones')">
        <app-icon name="History" [size]="15" />Gestiones históricas<span>Próximamente</span>
      </button>
    </nav>

    @if (tab() === 'pagos') {
      <app-payment-import />
    } @else if (tab() !== 'asignacion') {
      <section class="detail-panel import-coming-soon">
        <app-icon name="Clock3" [size]="22" />
        <div>
          <h2>
            {{ tab() === 'pagos' ? 'Importación de pagos' : 'Importación de gestiones históricas' }}
          </h2>
          <p>Esta fuente estará disponible en una siguiente etapa.</p>
        </div>
      </section>
    } @else {
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
          <label
            >Periodo
            <input
              type="month"
              [ngModel]="periodMonth"
              (ngModelChange)="changePeriod($event)"
              [disabled]="busy()"
            />
            <small>{{ periodLabel() }} · {{ period }}</small>
          </label>
          <label
            >Tipo
            <div class="locked-field">
              <app-icon name="FileSpreadsheet" [size]="14" />Asignación
            </div>
            <small>Disponible actualmente</small>
          </label>
          <div class="import-current">
            <span>Asignación actual</span>
            <strong>{{ number(config()?.existingRows ?? 0) }} registros</strong>
            <small>Se reemplaza únicamente con las filas correctas del archivo.</small>
          </div>
        </div>

        <input
          #fileInput
          class="import-file-input"
          id="assignment-file"
          type="file"
          accept=".csv,.txt,text/csv,text/plain"
          (change)="select($event)"
        />
        <label
          for="assignment-file"
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
              <app-icon name="ArrowDownToLine" [size]="15" />Importar asignación
            }
          </button>
          @if (current()) {
            <span class="import-status-text"
              ><span
                class="status-dot"
                [class.failed]="current()!.status === 'failed'"
                [class.done]="isCompleted(current()!.status)"
              ></span
              >{{ statusLabel(current()!.status) }}</span
            >
          }
          <button class="btn btn-secondary" type="button" (click)="downloadTemplate()">
            <app-icon name="ArrowDownToLine" [size]="15" />Descargar plantilla CSV
          </button>
        </div>

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
              [class.spin]="isInProgress(current()!.status)"
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
                  <td>Asignación</td>
                  <td>{{ formatPeriod(item.period) }}</td>
                  <td class="file-name-cell" [title]="item.originalFilename">
                    {{ item.originalFilename }}
                  </td>
                  <td>{{ number(item.rowsInserted || item.rowsTotal) }}</td>
                  <td>
                    <span class="import-status-chip" [attr.data-status]="item.status">{{
                      statusLabel(item.status)
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
                  <td colspan="9">No hay importaciones registradas.</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </section>
    }

    @if (detail()) {
      <div class="import-modal-backdrop" role="presentation">
        <section
          class="import-modal import-detail-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="detail-title"
        >
          <header>
            <app-icon name="FileSearch" [size]="19" />
            <h2 id="detail-title">Detalle de importación</h2>
          </header>
          <dl>
            <div>
              <dt>Archivo</dt>
              <dd>{{ detail()!.originalFilename }}</dd>
            </div>
            <div>
              <dt>Periodo</dt>
              <dd>{{ formatPeriod(detail()!.period) }}</dd>
            </div>
            <div>
              <dt>Usuario</dt>
              <dd>{{ detail()!.createdBy ?? '-' }}</dd>
            </div>
            <div>
              <dt>Inicio</dt>
              <dd>{{ dateTime(detail()!.startedAt) }}</dd>
            </div>
            <div>
              <dt>Fin</dt>
              <dd>{{ dateTime(detail()!.finishedAt) }}</dd>
            </div>
            <div>
              <dt>Duración</dt>
              <dd>{{ duration(detail()!.durationSeconds) }}</dd>
            </div>
            <div>
              <dt>Filas procesadas</dt>
              <dd>{{ number(detail()!.rowsProcessed) }}</dd>
            </div>
            <div>
              <dt>Filas insertadas</dt>
              <dd>{{ number(detail()!.rowsInserted) }}</dd>
            </div>
            <div>
              <dt>Rechazadas</dt>
              <dd>{{ number(detail()!.rowsRejected) }}</dd>
            </div>
          </dl>
          @if (detail()!.error) {
            <p class="form-error">{{ detail()!.error }}</p>
          }
          <footer>
            @if (detail()!.hasErrorsFile) {
              <button class="btn btn-secondary" type="button" (click)="downloadErrors(detail()!)">
                <app-icon name="ArrowDownToLine" [size]="15" />Descargar errores
              </button>
            }
            <button class="btn btn-primary" type="button" (click)="detail.set(null)">Cerrar</button>
          </footer>
        </section>
      </div>
    }
  </section>`,
})
export class ImportAssignment implements OnDestroy {
  private readonly api = inject(ImportApiService);
  private readonly feedback = inject(FeedbackService);
  private pollSubscription?: Subscription;

  readonly tab = signal<ImportTab>('asignacion');
  readonly config = signal<ImportConfiguration | null>(null);
  readonly file = signal<File | null>(null);
  readonly dragging = signal(false);
  readonly uploading = signal(false);
  readonly current = signal<ImportRecord | null>(null);
  readonly history = signal<ImportRecord[]>([]);
  readonly detail = signal<ImportRecord | null>(null);
  periodMonth = `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}`;
  period = this.periodMonth.replace('-', '');

  constructor() {
    this.loadConfiguration();
    this.loadHistory();
  }

  ngOnDestroy(): void {
    this.pollSubscription?.unsubscribe();
  }

  busy(): boolean {
    return this.uploading() || this.isInProgress(this.current()?.status);
  }

  changePeriod(value: string): void {
    this.periodMonth = value;
    this.period = value.replace('-', '');
    this.file.set(null);
    this.current.set(null);
    this.pollSubscription?.unsubscribe();
    this.loadConfiguration();
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
    this.pollSubscription?.unsubscribe();
  }

  importFile(): void {
    const selectedFile = this.file();
    if (!selectedFile) return;
    this.uploading.set(true);
    this.api.importAssignment(this.period, selectedFile).subscribe({
      next: (record) => {
        this.current.set(record);
        this.uploading.set(false);
        this.poll(record.id);
      },
      error: (error) => {
        this.uploading.set(false);
        this.feedback.show(error.error?.message ?? 'El archivo no pudo recibirse.');
      },
    });
  }

  loadHistory(): void {
    this.api.history('expertis_asignacion').subscribe({
      next: (response) => {
        this.history.set(response.data);
        const current = this.current();
        const refreshedCurrent = response.data.find((item) => item.id === current?.id);
        if (refreshedCurrent) {
          this.current.set(refreshedCurrent);
          return;
        }

        if (this.current() === null) {
          const resumable = response.data.find((item) => this.isInProgress(item.status));
          if (resumable) this.restoreImport(resumable);
        }
      },
      error: () => this.feedback.show('No se pudo obtener el historial de importaciones.'),
    });
  }

  showDetail(item: ImportRecord): void {
    this.detail.set(item);
    if (this.isInProgress(item.status)) this.restoreImport(item);
  }

  statusLabel(status: ImportStatus): string {
    return {
      uploaded: 'Archivo recibido',
      validating: 'Validando',
      validated: 'Validado',
      processing: 'Procesando',
      completed: 'Completado',
      completed_with_errors: 'Completado con errores',
      failed: 'Fallido',
      cancelled: 'Cancelado',
    }[status];
  }

  resultTitle(status: ImportStatus): string {
    if (this.isInProgress(status) || status === 'validated') return 'Procesando archivo...';
    if (status === 'completed_with_errors') return 'Importación completada con errores';
    if (status === 'completed') return 'Importación completada';
    return 'No se pudo importar el archivo';
  }

  resultDescription(record: ImportRecord): string {
    if (this.isInProgress(record.status) || record.status === 'validated')
      return 'El archivo se está validando y procesando automáticamente.';
    if (record.status === 'completed_with_errors')
      return `${this.number(record.rowsInserted)} filas fueron importadas correctamente y ${this.number(record.rowsRejected)} filas presentaron errores.`;
    if (record.status === 'completed')
      return `${this.number(record.rowsInserted)} filas fueron importadas correctamente.`;
    return record.error ?? 'Verifica la estructura del archivo e inténtalo nuevamente.';
  }

  isCompleted(status: ImportStatus): boolean {
    return status === 'completed' || status === 'completed_with_errors';
  }

  periodLabel(): string {
    return this.formatPeriod(this.period);
  }
  formatPeriod(value: string): string {
    const date = new Date(Number(value.slice(0, 4)), Number(value.slice(4, 6)) - 1, 1);
    return new Intl.DateTimeFormat('es-PE', { month: 'long', year: 'numeric' })
      .format(date)
      .replace(/^./, (letter) => letter.toUpperCase());
  }
  number(value: number | null | undefined): string {
    if (value === null || value === undefined) return '-';

    return new Intl.NumberFormat('es-PE').format(value);
  }

  fileSize(bytes: number): string {
    return bytes >= 1024 * 1024
      ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
      : `${Math.max(1, Math.round(bytes / 1024))} KB`;
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

    const totalSeconds = Math.max(0, Math.round(seconds));

    return totalSeconds < 60
      ? `${totalSeconds}s`
      : `${Math.floor(totalSeconds / 60)}m ${totalSeconds % 60}s`;
  }

  private setFile(value: File | null): void {
    if (!value) return;
    const allowed = this.config()?.allowedExtensions ?? ['csv', 'txt'];
    const extension = value.name.split('.').pop()?.toLowerCase() ?? '';
    if (!allowed.includes(extension)) {
      this.feedback.show('Formato no permitido. Utiliza un archivo CSV o TXT.');
      return;
    }
    if (this.config() && value.size > this.config()!.maxFileMb * 1024 * 1024) {
      this.feedback.show(`El archivo excede el límite de ${this.config()!.maxFileMb} MB.`);
      return;
    }
    this.file.set(value);
    this.current.set(null);
    this.pollSubscription?.unsubscribe();
  }

  private loadConfiguration(): void {
    this.api.configuration(this.period).subscribe({
      next: (configuration) => this.config.set(configuration),
      error: () => this.feedback.show('No se pudo obtener la configuración de importación.'),
    });
  }

  private restoreImport(record: ImportRecord): void {
    const periodChanged = this.period !== record.period;
    this.period = record.period;
    this.periodMonth = `${record.period.slice(0, 4)}-${record.period.slice(4, 6)}`;
    this.current.set(record);
    if (periodChanged) this.loadConfiguration();
    if (this.isInProgress(record.status)) this.poll(record.id);
  }

  private poll(importId: number): void {
    this.pollSubscription?.unsubscribe();
    this.pollSubscription = timer(0, 5000)
      .pipe(exhaustMap(() => this.api.status(importId)))
      .subscribe({
        next: (record) => {
          this.applyStatus(record);
          if (!this.isInProgress(record.status)) {
            this.pollSubscription?.unsubscribe();
            this.loadHistory();
            this.loadConfiguration();
            if (this.isCompleted(record.status))
              this.feedback.show(this.resultDescription({ ...this.current()!, ...record }));
          }
        },
        error: () => {
          this.pollSubscription?.unsubscribe();
          this.feedback.show('No se pudo consultar el estado de la importación.');
        },
      });
  }

  isInProgress(status: ImportStatus | undefined): boolean {
    return (
      status === 'uploaded' ||
      status === 'validating' ||
      status === 'validated' ||
      status === 'processing'
    );
  }

  private applyStatus(status: ImportStatusRecord): void {
    const current = this.current();
    if (current?.id === status.id) {
      this.current.set({ ...current, ...status });
    }
  }

  downloadErrors(record: ImportRecord): void {
    this.api.downloadErrors(record.id).subscribe({
      next: (file) => {
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = `errores-asignacion-${record.id}.csv`;
        link.click();
        URL.revokeObjectURL(url);
      },
      error: () => this.feedback.show('No se pudo descargar el archivo de errores.'),
    });
  }

  downloadTemplate(): void {
    this.api.template().subscribe({
      next: (file) => {
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'plantilla-asignacion-expertis.csv';
        link.click();
        URL.revokeObjectURL(url);
      },
      error: () => this.feedback.show('No se pudo descargar la plantilla CSV.'),
    });
  }
}
