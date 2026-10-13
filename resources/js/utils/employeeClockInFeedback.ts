import Swal from 'sweetalert2';
import { router } from '@inertiajs/react';

type GuardResponse = { status?: number; data?: unknown };
let activeAlert: ReturnType<typeof Swal.fire> | null = null;

export function showEmployeeClockInFeedback(response?: GuardResponse): boolean {
  const data = response?.data;
  if (response?.status !== 423 || typeof data !== 'object' || data === null
    || !('code' in data) || data.code !== 'EMPLOYEE_NOT_CLOCKED_IN') {
    activeAlert = null;
    return false;
  }

  if (!activeAlert) {
    const alert = Swal.fire({
      icon: 'error',
      title: 'Error',
      text: 'Please clock in on the Time In page before processing business actions.',
      confirmButtonText: 'OK',
    });
    activeAlert = alert;
    const clear = () => { if (activeAlert === alert) activeAlert = null; };
    void alert.then(clear, clear);
  }

  return true;
}

export function installEmployeeClockInFeedback(): void {
  // Legacy catch blocks must not replace the confirmed clock-in explanation with "Failed".
  Swal.fire = new Proxy(Swal.fire, {
    apply(target, receiver, args) {
      const options = typeof args[0] === 'object' && args[0] !== null ? args[0] : { icon: args[2] };
      if (activeAlert && ['error', 'warning'].includes(options.icon)
        && !options.toast && !options.showCancelButton && !options.showDenyButton
        && !options.input && !options.preConfirm && !options.preDeny) {
        return activeAlert;
      }

      return Reflect.apply(target, receiver, args);
    },
  });

  const originalFetch = window.fetch.bind(window);
  window.fetch = async (...args) => {
    const response = await originalFetch(...args).catch(error => {
      activeAlert = null;
      throw error;
    });
    if (response.status === 423) {
      try {
        showEmployeeClockInFeedback({ status: response.status, data: await response.clone().json() });
      } catch {
        // Leave malformed error bodies available for the caller's existing error handling.
        activeAlert = null;
      }
    } else if (!response.ok) {
      showEmployeeClockInFeedback({ status: response.status });
    }
    return response;
  };

  router.on('invalid', event => {
    if (showEmployeeClockInFeedback(event.detail.response)) event.preventDefault();
  });
}
