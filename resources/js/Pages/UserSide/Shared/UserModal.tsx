import Swal, { type SweetAlertCustomClass, type SweetAlertOptions } from 'sweetalert2';

const defaultCustomClass: SweetAlertCustomClass = {
	popup: 'user-swal2-popup',
	icon: 'user-swal2-icon',
	title: 'user-swal2-title',
	htmlContainer: 'user-swal2-text',
	actions: 'user-swal2-actions',
	confirmButton: 'user-swal2-confirm',
	cancelButton: 'user-swal2-cancel',
};

const defaultOptions: SweetAlertOptions = {
	background: '#f3f4f6',
	color: '#101828',
	iconColor: '#e5a865',
	showCloseButton: false,
	buttonsStyling: false,
	reverseButtons: true,
	customClass: defaultCustomClass,
	showClass: {
		popup: 'swal2-show user-swal2-show',
		backdrop: 'swal2-backdrop-show user-swal2-backdrop-show',
	},
	hideClass: {
		popup: 'swal2-hide user-swal2-hide',
		backdrop: 'swal2-backdrop-hide user-swal2-backdrop-hide',
	},
};

const UserSwal = Swal.mixin(defaultOptions);
const fire = UserSwal.fire.bind(UserSwal);

const joinClasses = (baseClass?: string, extraClass?: string) => {
	if (baseClass && extraClass) return `${baseClass} ${extraClass}`;
	return baseClass ?? extraClass;
};

const mergeCustomClass = (incoming?: SweetAlertCustomClass): SweetAlertCustomClass => ({
	...defaultCustomClass,
	...incoming,
	container: joinClasses(defaultCustomClass.container, incoming?.container),
	popup: joinClasses(defaultCustomClass.popup, incoming?.popup),
	icon: joinClasses(defaultCustomClass.icon, incoming?.icon),
	title: joinClasses(defaultCustomClass.title, incoming?.title),
	htmlContainer: joinClasses(defaultCustomClass.htmlContainer, incoming?.htmlContainer),
	actions: joinClasses(defaultCustomClass.actions, incoming?.actions),
	confirmButton: joinClasses(defaultCustomClass.confirmButton, incoming?.confirmButton),
	denyButton: joinClasses(defaultCustomClass.denyButton, incoming?.denyButton),
	cancelButton: joinClasses(defaultCustomClass.cancelButton, incoming?.cancelButton),
	loader: joinClasses(defaultCustomClass.loader, incoming?.loader),
	closeButton: joinClasses(defaultCustomClass.closeButton, incoming?.closeButton),
	validationMessage: joinClasses(defaultCustomClass.validationMessage, incoming?.validationMessage),
});

UserSwal.fire = ((...args: Parameters<typeof UserSwal.fire>) => {
	const [firstArg] = args;

	if (typeof firstArg !== 'object' || firstArg === null || Array.isArray(firstArg)) {
		return fire(...args);
	}

	const options = firstArg as SweetAlertOptions;
	return fire({
		...options,
		customClass: mergeCustomClass(options.customClass),
		showClass: {
			...defaultOptions.showClass,
			...(options.showClass ?? {}),
		},
		hideClass: {
			...defaultOptions.hideClass,
			...(options.hideClass ?? {}),
		},
	});
}) as typeof UserSwal.fire;

export default UserSwal;
