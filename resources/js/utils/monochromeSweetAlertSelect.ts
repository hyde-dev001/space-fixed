const triggerClassName = [
  'flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm text-gray-900 shadow-sm transition-colors',
  'hover:bg-gray-100 focus:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500/20',
  'disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:bg-gray-800 dark:focus:border-gray-400',
].join(' ');
const optionBaseClass = 'block w-full rounded-md px-3 py-2 text-left text-sm focus:outline-none';
const selectedOptionClass = 'bg-gray-950 text-white dark:bg-gray-950 dark:text-white';
const neutralOptionClass = 'text-gray-900 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-800';

let activeMenu: { wrapper: HTMLDivElement; close: () => void } | null = null;
let documentListenerInstalled = false;
let sweetAlertObserver: MutationObserver | null = null;

const ensureDocumentListener = () => {
  if (documentListenerInstalled || typeof document === 'undefined') return;

  document.addEventListener('mousedown', (event) => {
    if (activeMenu && !activeMenu.wrapper.contains(event.target as Node)) activeMenu.close();
  });
  documentListenerInstalled = true;
};

const labelForSelect = (select: HTMLSelectElement) => {
  if (!select.id) return null;
  return Array.from(document.querySelectorAll('label')).find((label) => label.htmlFor === select.id) ?? null;
};

export const enhanceSweetAlertSelect = (select: HTMLSelectElement) => {
  if (!select.parentElement || select.dataset.monochromeSelectEnhanced === 'true') return;

  ensureDocumentListener();
  select.dataset.monochromeSelectEnhanced = 'true';

  const parent = select.parentElement;
  const wrapper = document.createElement('div');
  wrapper.className = 'relative w-full';
  parent.replaceChild(wrapper, select);
  wrapper.appendChild(select);

  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = triggerClassName;
  trigger.setAttribute('role', 'combobox');
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-expanded', 'false');
  trigger.disabled = select.disabled;

  const label = labelForSelect(select);
  const labelledBy = select.getAttribute('aria-labelledby');
  if (labelledBy) {
    trigger.setAttribute('aria-labelledby', labelledBy);
  } else if (label) {
    const labelId = label.id || `${select.id}-label`;
    label.id = labelId;
    trigger.setAttribute('aria-labelledby', labelId);
  } else {
    trigger.setAttribute('aria-label', select.getAttribute('aria-label') || 'Select an option');
  }

  const listbox = document.createElement('div');
  listbox.id = `${select.id || 'monochrome-select'}-listbox`;
  listbox.setAttribute('role', 'listbox');
  listbox.className = 'absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-300 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-900';
  listbox.hidden = true;
  trigger.setAttribute('aria-controls', listbox.id);

  select.style.position = 'absolute';
  select.style.width = '1px';
  select.style.height = '1px';
  select.style.padding = '0';
  select.style.margin = '-1px';
  select.style.overflow = 'hidden';
  select.style.clip = 'rect(0, 0, 0, 0)';
  select.style.whiteSpace = 'nowrap';
  select.style.border = '0';
  select.style.opacity = '0';
  select.style.pointerEvents = 'none';
  select.tabIndex = -1;
  select.setAttribute('aria-hidden', 'true');

  wrapper.append(trigger, listbox);

  let activeIndex = select.selectedIndex >= 0 ? select.selectedIndex : 0;

  const closeMenu = () => {
    listbox.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    trigger.removeAttribute('aria-activedescendant');
    if (activeMenu?.wrapper === wrapper) activeMenu = null;
  };

  const setActiveIndex = (index: number) => {
    activeIndex = index;
    Array.from(listbox.children).forEach((child, childIndex) => {
      const option = child as HTMLButtonElement;
      const isSelected = option.getAttribute('aria-selected') === 'true';
      const isActive = childIndex === activeIndex;
      option.className = `${optionBaseClass} ${isSelected ? selectedOptionClass : isActive ? 'bg-gray-100 dark:bg-gray-800' : neutralOptionClass}`;
    });
    const activeOption = listbox.children[activeIndex] as HTMLElement | undefined;
    if (activeOption) trigger.setAttribute('aria-activedescendant', activeOption.id);
  };

  const render = () => {
    const selectedOption = select.options[select.selectedIndex];
    trigger.textContent = selectedOption?.textContent?.trim() || 'Select an option';
    trigger.value = select.value;
    listbox.replaceChildren();

    Array.from(select.options).forEach((option, index) => {
      const optionButton = document.createElement('button');
      optionButton.type = 'button';
      optionButton.id = `${listbox.id}-option-${index}`;
      optionButton.setAttribute('role', 'option');
      optionButton.setAttribute('aria-selected', String(option.selected));
      if (option.disabled) optionButton.setAttribute('aria-disabled', 'true');
      optionButton.disabled = option.disabled;
      optionButton.textContent = option.textContent?.trim() || 'Select an option';
      optionButton.className = `${optionBaseClass} ${option.selected ? selectedOptionClass : index === activeIndex ? 'bg-gray-100 dark:bg-gray-800' : neutralOptionClass} ${option.disabled ? 'cursor-not-allowed opacity-50' : ''}`;
      optionButton.addEventListener('mouseenter', () => setActiveIndex(index));
      optionButton.addEventListener('click', () => {
        if (option.disabled) return;
        select.selectedIndex = index;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        closeMenu();
        trigger.focus();
      });
      listbox.appendChild(optionButton);
    });
  };

  const openMenu = () => {
    if (select.disabled) return;
    activeMenu?.close();
    activeIndex = select.selectedIndex >= 0 ? select.selectedIndex : 0;
    render();
    listbox.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    setActiveIndex(activeIndex);
    activeMenu = { wrapper, close: closeMenu };
  };

  const moveActiveIndex = (direction: 1 | -1) => {
    const options = Array.from(select.options);
    if (!options.length) return;
    let nextIndex = activeIndex;
    for (let step = 0; step < options.length; step += 1) {
      nextIndex = (nextIndex + direction + options.length) % options.length;
      if (!options[nextIndex]?.disabled) {
        setActiveIndex(nextIndex);
        return;
      }
    }
  };

  trigger.addEventListener('click', () => {
    if (listbox.hidden) openMenu();
    else closeMenu();
  });
  trigger.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (listbox.hidden) openMenu();
      else moveActiveIndex(event.key === 'ArrowDown' ? 1 : -1);
      return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      if (listbox.hidden) {
        openMenu();
      } else if (!select.options[activeIndex]?.disabled) {
        select.selectedIndex = activeIndex;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        closeMenu();
      }
      return;
    }
    if (event.key === 'Escape' && !listbox.hidden) {
      event.preventDefault();
      closeMenu();
    }
  });
  select.addEventListener('change', render);

  render();
};

const enhanceExistingSweetAlertSelects = () => {
  document
    .querySelectorAll<HTMLSelectElement>('.swal2-container select:not([data-monochrome-select-enhanced])')
    .forEach(enhanceSweetAlertSelect);
};

export const installSweetAlertSelectObserver = () => {
  if (typeof document === 'undefined' || !document.body || typeof MutationObserver === 'undefined') return () => undefined;
  if (sweetAlertObserver) return () => sweetAlertObserver?.disconnect();

  enhanceExistingSweetAlertSelects();
  sweetAlertObserver = new MutationObserver(enhanceExistingSweetAlertSelects);
  sweetAlertObserver.observe(document.body, { childList: true, subtree: true });

  return () => {
    sweetAlertObserver?.disconnect();
    sweetAlertObserver = null;
  };
};
