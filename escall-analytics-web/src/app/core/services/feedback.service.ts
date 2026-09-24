import { Injectable, signal } from '@angular/core';
@Injectable({ providedIn: 'root' })
export class FeedbackService {
  readonly message = signal('');
  private timeout?: ReturnType<typeof setTimeout>;
  show(message: string): void {
    clearTimeout(this.timeout);
    this.message.set(message);
    this.timeout = setTimeout(() => this.message.set(''), 4000);
  }
}
