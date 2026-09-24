import { ApplicationConfig, inject, provideAppInitializer, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { routes } from './app.routes';
import { apiCredentialsInterceptor } from './core/interceptors/api-credentials.interceptor';
import { AuthService } from './core/services/auth.service';
export const appConfig: ApplicationConfig = { providers: [provideBrowserGlobalErrorListeners(), provideRouter(routes), provideHttpClient(withInterceptors([apiCredentialsInterceptor])), provideAppInitializer(() => inject(AuthService).restore())] };
