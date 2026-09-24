import { ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, input, output, signal } from '@angular/core';
import { Icon } from '../icon/icon';
import { MultiSelectOverlayService } from './multi-select-overlay.service';

let nextMultiSelectId = 0;

@Component({
  selector: 'app-multi-select',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(document:click)': 'outside($event)', '(keydown.escape)': 'close()' },
  template: `<div class="multi-select"><span class="field-label" [id]="labelId()">{{ label() }}</span><button type="button" class="multi-trigger" [attr.aria-label]="label()" [attr.aria-expanded]="open()" [attr.aria-controls]="popoverId" (click)="toggleOpen()"><span>{{ summary() }}</span><app-icon name="ChevronDown" [size]="13" /></button>
    @if (open()) { <div class="multi-popover" [id]="popoverId" role="group" [attr.aria-labelledby]="labelId()"><div class="multi-heading"><span>{{ label() }}</span><button type="button" (click)="clear()">Limpiar</button></div>
      @if (showSearch()) { <label class="multi-search"><app-icon name="Search" [size]="13" /><input type="search" [attr.aria-label]="'Buscar ' + label().toLowerCase()" [placeholder]="'Buscar ' + label().toLowerCase() + '...'" [value]="query()" (input)="setQuery($event)" /></label> }
      <div class="multi-options"><label class="multi-option multi-select-all"><input type="checkbox" [checked]="allSelected()" [indeterminate]="partiallySelected()" (change)="toggleAll()" /><span>Seleccionar todos</span></label>
        @for (option of filteredOptions(); track option) { <label class="multi-option"><input type="checkbox" [checked]="selected().includes(option)" (change)="toggle(option)" /><span>{{ option }}</span></label> }
        @if (!filteredOptions().length) { <p class="multi-empty">Sin resultados</p> }
      </div>
    </div> }
  </div>`,
})
export class MultiSelect {
  private readonly element = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly overlays = inject(MultiSelectOverlayService);
  private readonly id = `multi-select-${++nextMultiSelectId}`;
  readonly popoverId = `${this.id}-popover`;
  readonly label = input.required<string>();
  readonly options = input.required<readonly string[]>();
  readonly selected = input<readonly string[]>([]);
  readonly allLabel = input('Todos');
  readonly selectionChange = output<string[]>();
  readonly open = signal(false);
  readonly query = signal('');
  readonly filteredOptions = computed(() => {
    const query = this.query().trim().toLocaleLowerCase();
    return query ? this.options().filter(option => option.toLocaleLowerCase().includes(query)) : this.options();
  });
  readonly showSearch = computed(() => this.options().length > 6);
  readonly allSelected = computed(() => this.options().length > 0 && this.options().every(option => this.selected().includes(option)));
  readonly partiallySelected = computed(() => !this.allSelected() && this.selected().some(option => this.options().includes(option)));
  readonly summary = computed(() => {
    const selected = this.selected().filter(option => this.options().includes(option));
    if (!selected.length || selected.length === this.options().length) return this.allLabel();
    return selected.length === 1 ? selected[0] : `${selected[0]} + ${selected.length - 1}`;
  });

  constructor() {
    effect(() => {
      if (this.overlays.activeId() !== this.id && this.open()) this.open.set(false);
    });
  }

  labelId(): string { return `filter-${this.label().toLowerCase().replaceAll(' ', '-')}`; }

  toggleOpen(): void {
    if (this.open()) { this.close(); return; }
    this.query.set('');
    this.overlays.activate(this.id);
    this.open.set(true);
  }

  toggle(value: string): void {
    this.selectionChange.emit(this.selected().includes(value) ? this.selected().filter(item => item !== value) : [...this.selected(), value]);
  }

  toggleAll(): void { this.selectionChange.emit(this.allSelected() ? [] : [...this.options()]); }

  clear(): void { this.selectionChange.emit([]); }

  setQuery(event: Event): void { this.query.set((event.target as HTMLInputElement).value); }

  close(): void { this.open.set(false); this.overlays.clear(this.id); }

  outside(event: Event): void {
    if (event.target instanceof Node && !this.element.nativeElement.contains(event.target)) this.close();
  }
}
