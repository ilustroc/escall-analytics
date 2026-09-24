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
    <section class="detail-panel import-workspace">
      <header class="detail-header">
        <div>
          <h2>Nueva importación</h2>
          <p>Los pagos se validan en segundo plano antes de incorporarse a Analytics.</p>
        </div>
        @if (config()) {
          <span class="import-limit">Hasta {{ number(config()!.maxRows) }} filas · {{ config()!.maxFileMb }} MB</span>
        }
      </header>

      <div class="import-fields">
        <label>
          Periodo
          <div class="locked-field"><app-icon name="CalendarDays" [size]="14" />Desde FECHA_PAGO</div>
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

      <input #fileInput class="import-file-input" id="payment-file" type="file" accept=".csv,.txt,text/csv,text/plain" (change)="select($event)" />
      <label for="payment-file" class="import-dropzone" [class.dragging]="dragging()" [class.has-file]="file()" (dragover)="dragOver($event)" (dragleave)="dragging.set(false)" (drop)="drop($event)">
        <app-icon [name]="file() ? 'FileCheck2' : 'UploadCloud'" [size]="25" />
        @if (file()) {
          <div><strong>{{ file()!.name }}</strong><span>{{ fileSize(file()!.size) }} · {{ file()!.name.split('.').pop()?.toUpperCase() }}</span></div>
          <button type="button" class="dropzone-remove" (click)="clearFile($event)" aria-label="Quitar archivo"><app-icon name="X" [size]="15" /></button>
        } @else {
          <div><strong>Arrastra tu archivo aquí</strong><span>o selecciona un archivo</span></div>
          <small>Formatos permitidos: CSV y TXT</small>
        }
      </label>

      <div class="import-actions">
        <button class="btn btn-primary" type="button" (click)="validate()" [disabled]="busy() || !file()">
          @if (uploading()) { <app-icon name="LoaderCircle" [size]="15" />Subiendo... } @else { <app-icon name="ShieldCheck" [size]="15" />Validar archivo }
        </button>
        @if (current()) {
          <span class="import-status-text"><span class="status-dot" [class.failed]="current()!.status === 'failed'" [class.done]="current()!.status === 'completed'"></span>{{ label(current()!.status) }}</span>
        }
        <button class="btn btn-secondary" type="button" (click)="toggleManual()"><app-icon name="Wallet" [size]="15" />Registrar pago individual</button>
        <button class="btn btn-secondary" type="button" (click)="template()"><app-icon name="ArrowDownToLine" [size]="15" />Descargar plantilla CSV</button>
      </div>
      <p class="import-payment-hint">Para archivos grandes recomendamos CSV por su mayor velocidad de procesamiento.</p>

      @if (manualOpen()) {
        <section class="import-progress-card manual-payment-form" aria-label="Registrar pago individual">
          <header><div><h3>Registrar pago individual</h3><p>El registro se valida contra la asignación del período antes de importarse.</p></div><button type="button" class="table-link" (click)="toggleManual()">Cerrar</button></header>
          <div class="import-fields manual-fields">
            <label>Fecha de pago<input type="date" [(ngModel)]="manual.fechaPago" [disabled]="busy()" /></label>
            <label>Código<input type="text" [(ngModel)]="manual.codigo" placeholder="27834618-TRUJILLO" [disabled]="busy()" /></label>
            <label>Monto<input type="number" min="0.01" step="0.01" [(ngModel)]="manual.monto" placeholder="0.00" [disabled]="busy()" /></label>
            <label>Ejecutivo<input type="text" [(ngModel)]="manual.ejecutivo" placeholder="Nombre del ejecutivo" [disabled]="busy()" /></label>
            <label>Tipo de acuerdo<select [(ngModel)]="manual.tipoAcuerdo" [disabled]="busy()"><option value="PPC">PPC</option><option value="PPM">PPM</option><option value="PAR">PAR</option></select></label>
          </div>
          <footer><button class="btn btn-primary" type="button" (click)="validateManual()" [disabled]="busy()"><app-icon name="ShieldCheck" [size]="15" />Validar pago</button></footer>
        </section>
      }

      @if (current()) {
        <section class="import-progress-card" [class.failed]="current()!.status === 'failed'" [class.completed]="current()!.status === 'completed'">
          <div class="progress-title"><div><h3>{{ label(current()!.status) }}</h3><p>{{ stageDescription(current()!.status) }}</p></div><strong>{{ progressLabel(current()!) }}</strong></div>
          <div class="progress-track"><span [style.width.%]="current()!.progress"></span></div>
          <ol class="import-stages"><li [class.done]="stageDone('received')">Archivo recibido</li><li [class.done]="stageDone('structure')">Estructura validada</li><li [class.done]="stageDone('validated')">Pagos validados</li><li [class.done]="stageDone('processing')">Cargando pagos</li><li [class.done]="stageDone('completed')">Completado</li></ol>
        </section>

        @if (current()!.status === 'validated' || current()!.status === 'processing' || current()!.status === 'completed' || current()!.status === 'failed') {
          <div class="import-summary import-summary-expanded">
            <div><span>Filas</span><strong>{{ number(current()!.rowsTotal) }}</strong></div>
            <div><span>Clientes</span><strong>{{ number(current()!.clients) }}</strong></div>
            <div><span>Cuentas</span><strong>{{ number(current()!.accounts) }}</strong></div>
            <div><span>Válidos</span><strong>{{ number(current()!.rowsInserted) }}</strong></div>
            <div><span>Rechazados</span><strong [class.error-value]="current()!.rowsRejected > 0">{{ number(current()!.rowsRejected) }}</strong></div>
          </div>
        }
        @if (current()!.error) { <p class="form-error">{{ current()!.error }}</p> }
        @if (current()!.errors.length) { <details class="import-errors"><summary>Ver {{ current()!.errors.length }} errores de validación</summary><ul>@for (error of current()!.errors; track $index) { <li>Fila {{ error.row ?? '-' }} · {{ error.column }}: {{ error.message }}</li> }</ul></details> }
        @if (current()!.status === 'validated') {
          <div class="replacement-notice"><app-icon name="Info" [size]="15" /><span><strong>{{ number(current()!.rowsInserted) }} pagos válidos</strong> se agregarán. Los rechazados no serán considerados.</span></div>
          <button class="btn btn-primary" type="button" (click)="start()"><app-icon name="ArrowDownToLine" [size]="15" />Importar {{ number(current()!.rowsInserted) }} pagos</button>
        }
      }
    </section>

    <section class="detail-panel import-history">
      <header class="detail-header"><div><h2>Historial de importaciones</h2><p>{{ history().length }} registros recientes</p></div><button class="btn btn-secondary" type="button" (click)="loadHistory()"><app-icon name="RefreshCw" [size]="14" />Actualizar</button></header>
      <div class="users-table-wrap"><table class="compact-table import-history-table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Periodo</th><th>Archivo</th><th>Filas</th><th>Estado</th><th>Usuario</th><th>Duración</th><th>Acciones</th></tr></thead><tbody>
        @for (item of history(); track item.id) {<tr><td>{{ dateTime(item.createdAt) }}</td><td>Pagos</td><td>Varios períodos</td><td class="file-name-cell" [title]="item.originalFilename">{{ item.originalFilename }}</td><td>{{ number(item.rowsInserted || item.rowsTotal) }}</td><td><span class="import-status-chip" [attr.data-status]="item.status">{{ label(item.status) }}</span></td><td>{{ item.createdBy ?? '-' }}</td><td>{{ duration(item.durationSeconds) }}</td><td><button type="button" class="table-link" (click)="showDetail(item)">Ver detalle</button></td></tr>}
        @empty {<tr><td colspan="9" class="table-empty">Aún no existen importaciones de pagos.</td></tr>}
      </tbody></table></div>
    </section>
  `,
  styles: [`.import-payment-hint{margin:-3px 13px 12px;color:#7186a6;font-size:12px}.manual-payment-form{margin:0 13px 13px;padding:11px 12px;background:#fbfdff}.manual-payment-form header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.manual-payment-form h3{margin:0;color:#24476e;font-size:11px}.manual-payment-form p{margin:3px 0 0;color:#7c8ba0;font-size:9px}.manual-fields{grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px;margin-top:12px;padding:0;border:0}.manual-fields input,.manual-fields select{width:100%;box-sizing:border-box;height:34px;border:1px solid #d6e1ef;border-radius:7px;background:#fff;color:#24476e;padding:0 10px;font-size:12px}.manual-payment-form footer{margin-top:12px}@media(max-width:1100px){.manual-fields{grid-template-columns:repeat(3,minmax(160px,1fr))}}@media(max-width:650px){.manual-fields{grid-template-columns:1fr}.manual-payment-form header{flex-direction:column}}`],
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
    this.api.paymentConfiguration().subscribe({ next: value => this.config.set(value) });
    this.loadHistory();
  }

  ngOnDestroy(): void { this.poller?.unsubscribe(); }
  busy(): boolean { return this.uploading() || this.inProgress(this.current()?.status); }
  select(event: Event): void { const input = event.target as HTMLInputElement; this.setFile(input.files?.item(0) ?? null); input.value = ''; }
  dragOver(event: DragEvent): void { event.preventDefault(); this.dragging.set(true); }
  drop(event: DragEvent): void { event.preventDefault(); this.dragging.set(false); this.setFile(event.dataTransfer?.files.item(0) ?? null); }
  clearFile(event: Event): void { event.preventDefault(); event.stopPropagation(); this.file.set(null); this.current.set(null); this.poller?.unsubscribe(); }
  toggleManual(): void { this.manualOpen.update(value => !value); }

  validate(): void {
    const file = this.file(); if (!file) return;
    this.uploading.set(true);
    this.api.validatePayments(file).subscribe({ next: item => { this.uploading.set(false); this.current.set(item); this.poll(item.id); }, error: error => { this.uploading.set(false); this.feedback.show(error.error?.message ?? 'No se pudo recibir el archivo.'); } });
  }
  validateManual(): void {
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
    this.api.validatePayments(file).subscribe({ next: item => { this.uploading.set(false); this.current.set(item); this.manualOpen.set(false); this.poll(item.id); }, error: error => { this.uploading.set(false); this.feedback.show(error.error?.message ?? 'No se pudo validar el pago.'); } });
  }
  start(): void { const item = this.current(); if (!item || item.status !== 'validated') return; this.api.importPayments(item.id).subscribe({ next: value => { this.current.set(value); this.poll(value.id); }, error: error => this.feedback.show(error.error?.message ?? 'No se pudo iniciar la importación.') }); }
  template(): void { this.api.paymentTemplate().subscribe({ next: file => { const url = URL.createObjectURL(file); const link = document.createElement('a'); link.href = url; link.download = 'plantilla-pagos-expertis.csv'; link.click(); URL.revokeObjectURL(url); }, error: () => this.feedback.show('No se pudo descargar la plantilla CSV.') }); }
  showDetail(item: ImportRecord): void { this.current.set(item); if (this.inProgress(item.status)) this.poll(item.id); }

  label(status: ImportStatus): string { return ({ uploaded: 'Archivo recibido', validating: 'Validando', validated: 'Validado', processing: 'Procesando', completed: 'Completado', failed: 'Fallido', cancelled: 'Cancelado' })[status]; }
  stageDescription(status: ImportStatus): string { return ({ uploaded: 'El archivo está en espera de validación.', validating: 'Validando estructura y pagos de forma segura.', validated: 'Archivo validado. Puedes confirmar la importación.', processing: 'Agregando pagos válidos de forma transaccional.', completed: 'Los pagos quedaron disponibles para Analytics.', failed: 'No se realizaron inserciones de pagos.', cancelled: 'La importación fue cancelada.' })[status]; }
  stageDone(stage: 'received' | 'structure' | 'validated' | 'processing' | 'completed'): boolean { const value = { uploaded: 1, validating: 2, validated: 3, processing: 4, completed: 5, failed: 0, cancelled: 0 }[this.current()?.status ?? 'uploaded']; return value >= ({ received: 1, structure: 2, validated: 3, processing: 4, completed: 5 }[stage]); }
  progressLabel(item: ImportRecord): string { return item.rowsTotal === null ? `Procesando ${this.number(item.rowsProcessed)} filas` : `${item.progress}%`; }
  number(value: number | null | undefined): string { return new Intl.NumberFormat('es-PE').format(value ?? 0); }
  fileSize(value: number): string { return value >= 1048576 ? `${(value / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(value / 1024))} KB`; }
  dateTime(value: string | null): string { return value ? new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '-'; }
  duration(seconds: number | null): string { if (seconds === null) return '-'; const total = Math.max(0, Math.round(seconds)); return total < 60 ? `${total}s` : `${Math.floor(total / 60)}m ${total % 60}s`; }

  private setFile(file: File | null): void { if (!file) return; const extension = file.name.split('.').pop()?.toLowerCase() ?? ''; if (!['csv', 'txt'].includes(extension)) { this.feedback.show('Formato no permitido. Utiliza un archivo CSV o TXT.'); return; } if (this.config() && file.size > this.config()!.maxFileMb * 1048576) { this.feedback.show(`El archivo excede el límite de ${this.config()!.maxFileMb} MB.`); return; } this.file.set(file); this.current.set(null); this.poller?.unsubscribe(); }
  private poll(id: number): void { this.poller?.unsubscribe(); this.poller = timer(0, 5000).pipe(exhaustMap(() => this.api.status(id))).subscribe({ next: status => { this.apply(status); if (!this.inProgress(status.status)) { this.poller?.unsubscribe(); this.loadHistory(); if (status.status === 'completed') this.feedback.show('Importación de pagos completada.'); } }, error: () => { this.poller?.unsubscribe(); this.feedback.show('No se pudo consultar el estado de la importación.'); } }); }
  loadHistory(): void { this.api.history('expertis_pagos').subscribe({ next: result => this.history.set(result.data) }); }
  private apply(status: ImportStatusRecord): void { const item = this.current(); if (item?.id === status.id) this.current.set({ ...item, ...status }); }
  private inProgress(status: ImportStatus | undefined): boolean { return status === 'uploaded' || status === 'validating' || status === 'processing'; }
}
