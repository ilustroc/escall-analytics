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
            <p>La asignación se valida en segundo plano antes de reemplazar el periodo.</p>
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
            <small>Se reemplaza solo tras una validación completa.</small>
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
            (click)="validate()"
            [disabled]="busy() || !file()"
          >
            @if (uploading()) {
              <app-icon name="LoaderCircle" [size]="15" />Subiendo...
            } @else {
              <app-icon name="ShieldCheck" [size]="15" />Validar archivo
            }
          </button>
          @if (current()) {
            <span class="import-status-text"
              ><span
                class="status-dot"
                [class.failed]="current()!.status === 'failed'"
                [class.done]="current()!.status === 'completed'"
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
            class="import-progress-card"
            [class.failed]="current()!.status === 'failed'"
            [class.completed]="current()!.status === 'completed'"
          >
            <div class="progress-title">
              <div>
                <h3>{{ statusLabel(current()!.status) }}</h3>
                <p>{{ stageDescription(current()!.status) }}</p>
              </div>
              <strong>{{ progressLabel(current()!) }}</strong>
            </div>
            <div class="progress-track"><span [style.width.%]="current()!.progress"></span></div>
            <ol class="import-stages">
              <li [class.done]="stageDone('received')">Archivo recibido</li>
              <li [class.done]="stageDone('structure')">Estructura validada</li>
              <li [class.done]="stageDone('validated')">Datos validados</li>
              <li [class.done]="stageDone('processing')">Cargando asignación</li>
              <li [class.done]="stageDone('completed')">Completado</li>
            </ol>
          </section>

          @if (
            current()!.status === 'validated' ||
            current()!.status === 'processing' ||
            current()!.status === 'completed' ||
            current()!.status === 'failed'
          ) {
            <div class="import-summary import-summary-expanded">
              <div>
                <span>Filas</span><strong>{{ number(current()!.rowsTotal) }}</strong>
              </div>
              <div>
                <span>Clientes</span><strong>{{ number(current()!.clients) }}</strong>
              </div>
              <div>
                <span>Cuentas</span><strong>{{ number(current()!.accounts) }}</strong>
              </div>
              <div>
                <span>Duplicados</span
                ><strong [class.error-value]="current()!.duplicates > 0">{{
                  number(current()!.duplicates)
                }}</strong>
              </div>
              <div>
                <span>Errores</span
                ><strong [class.error-value]="current()!.rowsRejected > 0">{{
                  number(current()!.rowsRejected)
                }}</strong>
              </div>
            </div>
          }

          @if (current()!.error) {
            <p class="form-error">{{ current()!.error }}</p>
          }
          @if (current()!.errors.length) {
            <details class="import-errors">
              <summary>Ver {{ current()!.errors.length }} errores de validación</summary>
              <ul>
                @for (error of current()!.errors; track $index) {
                  <li>Fila {{ error.row ?? '-' }} · {{ error.column }}: {{ error.message }}</li>
                }
              </ul>
            </details>
          }

          @if (current()!.status === 'validated') {
            <div class="replacement-notice">
              <app-icon name="Info" [size]="15" /><span
                >Esta importación reemplazará completamente la asignación del periodo
                <strong>{{ periodLabel() }}</strong> una vez validada.</span
              >
            </div>
            <button class="btn btn-primary" type="button" (click)="confirmReplacement()">
              <app-icon name="ArrowDownToLine" [size]="15" />Importar
              {{ number(current()!.rowsTotal) }} filas
            </button>
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

    @if (confirming()) {
      <div class="import-modal-backdrop" role="presentation">
        <section
          class="import-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="replace-title"
        >
          <header>
            <app-icon name="TriangleAlert" [size]="19" />
            <h2 id="replace-title">Reemplazar asignación</h2>
          </header>
          <p>
            La asignación actual de <strong>{{ periodLabel() }}</strong> tiene
            <strong>{{ number(config()?.existingRows ?? 0) }} registros</strong>. Se sustituirá por
            <strong>{{ number(current()?.rowsTotal ?? 0) }} filas</strong> solo cuando el proceso
            confirme el reemplazo.
          </p>
          <footer>
            <button class="btn btn-secondary" type="button" (click)="confirming.set(false)">
              Cancelar</button
            ><button class="btn btn-primary" type="button" (click)="startImport()">
              Reemplazar asignación
            </button>
          </footer>
        </section>
      </div>
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
  readonly confirming = signal(false);
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

  validate(): void {
    const selectedFile = this.file();
    if (!selectedFile) return;
    this.uploading.set(true);
    this.api.validate(this.period, selectedFile).subscribe({
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

  confirmReplacement(): void {
    if (this.current()?.existingRows ?? this.config()?.existingRows) {
      this.confirming.set(true);
      return;
    }
    this.startImport();
  }

  startImport(): void {
    const record = this.current();
    if (!record || record.status !== 'validated') return;
    this.confirming.set(false);
    this.api.import(record.id).subscribe({
      next: (updated) => {
        this.current.set(updated);
        this.poll(updated.id);
      },
      error: (error) =>
        this.feedback.show(error.error?.message ?? 'No se pudo iniciar la importación.'),
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

        // A page refresh must not hide an import that is waiting for the
        // explicit replacement confirmation, or one still being validated.
        if (this.current() === null) {
          const resumable = response.data.find(
            (item) => item.status === 'validated' || this.isInProgress(item.status),
          );
          if (resumable) this.restoreImport(resumable);
        }
      },
      error: () => this.feedback.show('No se pudo obtener el historial de importaciones.'),
    });
  }

  showDetail(item: ImportRecord): void {
    this.detail.set(item);
    if (item.status === 'validated' || this.isInProgress(item.status)) this.restoreImport(item);
  }

  statusLabel(status: ImportStatus): string {
    return {
      uploaded: 'Archivo recibido',
      validating: 'Validando',
      validated: 'Validado',
      processing: 'Procesando',
      completed: 'Completado',
      failed: 'Fallido',
      cancelled: 'Cancelado',
    }[status];
  }

  stageDescription(status: ImportStatus): string {
    return {
      uploaded: 'El archivo está en espera de validación.',
      validating: 'Validando estructura y cargando staging de forma segura.',
      validated: 'Archivo validado. Puedes confirmar el reemplazo del periodo.',
      processing: 'Reemplazando la asignación de forma atómica.',
      completed: 'La asignación quedó disponible para Analytics.',
      failed: 'No se realizaron cambios en la asignación.',
      cancelled: 'La importación fue cancelada.',
    }[status];
  }

  stageDone(stage: 'received' | 'structure' | 'validated' | 'processing' | 'completed'): boolean {
    const status = this.current()?.status;
    const order: Record<ImportStatus, number> = {
      uploaded: 1,
      validating: 2,
      validated: 3,
      processing: 4,
      completed: 5,
      failed: 0,
      cancelled: 0,
    };
    const expected = { received: 1, structure: 2, validated: 3, processing: 4, completed: 5 }[
      stage
    ];
    return status !== undefined && order[status] >= expected;
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

  progressLabel(record: ImportRecord): string {
    return record.rowsTotal === null
      ? `Procesando ${this.number(record.rowsProcessed)} filas`
      : `${record.progress}%`;
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
            if (record.status === 'completed')
              this.feedback.show(
                `Asignación ${this.current()?.period ?? ''} importada: ${this.number(record.rowsInserted)} filas.`,
              );
          }
        },
        error: () => {
          this.pollSubscription?.unsubscribe();
          this.feedback.show('No se pudo consultar el estado de la importación.');
        },
      });
  }

  private isInProgress(status: ImportStatus | undefined): boolean {
    return status === 'uploaded' || status === 'validating' || status === 'processing';
  }

  private applyStatus(status: ImportStatusRecord): void {
    const current = this.current();
    if (current?.id === status.id) {
      this.current.set({ ...current, ...status });
    }
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
