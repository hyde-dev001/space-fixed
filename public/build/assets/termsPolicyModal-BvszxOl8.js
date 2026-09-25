import r from"./UserModal-CexdwQOr.js";const l=`
  <div class="terms-modal">
    <div class="terms-modal__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <rect x="8" y="3" width="8" height="4" rx="1"></rect>
        <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"></path>
        <path d="M9 12h6"></path>
        <path d="M9 16h6"></path>
      </svg>
    </div>
    <p class="terms-modal__intro">
      Please read these terms before creating your account.
    </p>

    <div class="terms-modal__scroll">
      <h3>1. Acceptance of Terms</h3>
      <p>
        By continuing registration, you confirm that you have read, understood, and agreed to these Terms and Conditions and our account verification requirements.
      </p>

      <h3>2. Information We Request</h3>
      <p>
        We ask for your basic personal details and a supported identity document for document screening, account security, and marketplace protection.
      </p>

      <h3>3. Security and Anti-Fraud Policy</h3>
      <p>
        Screening helps us detect and prevent scam accounts, impersonation, and unauthorized activity. Uploads that do not plausibly match the selected ID type must be replaced before registration.
      </p>

      <h3>4. Accuracy of Information</h3>
      <p>
        You agree to provide true, complete, and updated information. Submitting misleading details or invalid IDs is a violation of platform policy.
      </p>

      <h3>5. Data Protection</h3>
      <p>
        Your data is processed for security, compliance, and account verification purposes. We apply reasonable safeguards to protect submitted information.
      </p>

      <h3>6. User Responsibility</h3>
      <p>
        You are responsible for keeping your account credentials confidential and for all activities under your account.
      </p>

      <h3>7. Agreement</h3>
      <p>
        Choosing <strong>Accept</strong> means you agree to these terms and consent to document screening as part of account security.
      </p>
    </div>
    <p class="terms-modal__hint">Scroll to the bottom to enable the Accept button.</p>
  </div>
`,d=async(a,n)=>r.fire({title:a,html:n,showCancelButton:!0,confirmButtonText:"Accept",cancelButtonText:"Decline",allowOutsideClick:!1,allowEscapeKey:!0,didOpen:()=>{const t=r.getConfirmButton(),e=document.querySelector(".terms-modal__scroll");if(!t||!e)return;t.disabled=!0;const o=()=>{const s=e.scrollTop+e.clientHeight>=e.scrollHeight-8;t.disabled=!s};e.addEventListener("scroll",o,{passive:!0}),o()},customClass:{popup:"user-terms-modal-popup",title:"user-terms-modal-title",htmlContainer:"user-terms-modal-content",actions:"user-terms-modal-actions",confirmButton:"user-terms-modal-accept",cancelButton:"user-terms-modal-decline"}});export{l as C,d as o};
