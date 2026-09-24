import { ChangeDetectionStrategy, Component, input } from '@angular/core';
@Component({ selector: 'app-page-header', changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<div class="page-header"><div><div class="flex items-center gap-2.5"><h1>{{ title() }}</h1></div><p>{{ subtitle() }}</p></div><div class="page-header-actions"><ng-content /></div></div>` })
export class PageHeader { readonly title = input.required<string>(); readonly subtitle = input.required<string>(); }
