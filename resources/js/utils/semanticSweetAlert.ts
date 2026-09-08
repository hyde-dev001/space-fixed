import type { SweetAlertCustomClass, SweetAlertOptions } from 'sweetalert2';

export type SweetAlertSemantic = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'signout';

type SweetAlertClassValue = string | readonly string[] | undefined;

const appendClass = (value: SweetAlertClassValue, className: string): string | readonly string[] => {
  if (Array.isArray(value)) {
    return [...value, className];
  }

  return value ? `${value} ${className}` : className;
};

export const withSweetAlertSemantic = (
  options: SweetAlertOptions,
  semantic: SweetAlertSemantic,
): SweetAlertOptions => {
  const semanticClass = `erp-swal2-${semantic}`;
  const customClass: SweetAlertCustomClass = options.customClass ?? {};

  return {
    ...options,
    customClass: {
      ...customClass,
      popup: appendClass(customClass.popup, semanticClass),
      icon: appendClass(customClass.icon, semanticClass),
      confirmButton: appendClass(customClass.confirmButton, semanticClass),
      denyButton: appendClass(customClass.denyButton, semanticClass),
      cancelButton: appendClass(customClass.cancelButton, semanticClass),
    },
  };
};
