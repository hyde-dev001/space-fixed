import Swal from '@/Pages/UserSide/Shared/UserModal';

export const openTermsPolicyModal = async (title: string, htmlContent: string) => {
  return Swal.fire({
    title,
    html: htmlContent,
    showCancelButton: true,
    confirmButtonText: 'Accept',
    cancelButtonText: 'Decline',
    allowOutsideClick: false,
    allowEscapeKey: true,
    didOpen: () => {
      const confirmButton = Swal.getConfirmButton();
      const scrollBox = document.querySelector('.terms-modal__scroll') as HTMLElement | null;
      if (!confirmButton || !scrollBox) return;

      confirmButton.disabled = true;

      const unlock = () => {
        const threshold = 8;
        const reachedBottom = scrollBox.scrollTop + scrollBox.clientHeight >= scrollBox.scrollHeight - threshold;
        confirmButton.disabled = !reachedBottom;
      };

      scrollBox.addEventListener('scroll', unlock, { passive: true });
      unlock();
    },
    customClass: {
      popup: 'user-terms-modal-popup',
      title: 'user-terms-modal-title',
      htmlContainer: 'user-terms-modal-content',
      actions: 'user-terms-modal-actions',
      confirmButton: 'user-terms-modal-accept',
      cancelButton: 'user-terms-modal-decline',
    },
  });
};