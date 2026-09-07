import {
  Children,
  Fragment,
  forwardRef,
  isValidElement,
  useEffect,
  useId,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
  type ChangeEvent,
  type ChangeEventHandler,
  type FocusEventHandler,
  type KeyboardEventHandler,
  type MouseEventHandler,
  type ReactNode,
  type SelectHTMLAttributes,
} from "react";
import { ChevronDown } from "lucide-react";

export interface Option {
  value: string;
  label: ReactNode;
  disabled?: boolean;
}

type SelectValue = string | number | readonly string[] | undefined;

type SharedSelectProps = Omit<
  SelectHTMLAttributes<HTMLSelectElement>,
  "children" | "className" | "defaultValue" | "onChange" | "value"
> & {
  children?: ReactNode;
  className?: string;
  label?: ReactNode;
  defaultValue?: SelectValue;
  value?: SelectValue;
};

type OptionsSelectProps = SharedSelectProps & {
  options: Option[];
  placeholder?: string;
  onChange: (value: string) => void;
};

type NativeOptionsSelectProps = SharedSelectProps & {
  options?: undefined;
  onChange?: ChangeEventHandler<HTMLSelectElement>;
};

export type SelectProps = OptionsSelectProps | NativeOptionsSelectProps;

type OptionElementProps = {
  value?: string | number;
  children?: ReactNode;
  disabled?: boolean;
  selected?: boolean;
};

const asSelectValue = (value: SelectValue): string => {
  if (Array.isArray(value)) return value[0] ?? "";
  return value === undefined ? "" : String(value);
};

const textFromNode = (node: ReactNode): string => {
  if (Array.isArray(node)) return node.map(textFromNode).join("");
  if (isValidElement(node)) return textFromNode((node.props as OptionElementProps).children);
  return node === null || node === undefined || typeof node === "boolean" ? "" : String(node);
};

const optionsFromChildren = (children: ReactNode): Option[] => {
  const result: Option[] = [];

  Children.forEach(children, (child) => {
    if (!isValidElement(child)) return;

    if (child.type === Fragment) {
      result.push(...optionsFromChildren((child.props as { children?: ReactNode }).children));
      return;
    }

    if (child.type !== "option") return;
    const props = child.props as OptionElementProps;
    result.push({
      value: props.value === undefined ? textFromNode(props.children) : String(props.value),
      label: props.children,
      disabled: props.disabled,
    });
  });

  return result;
};

const marginUtilities = /^(?:[a-z-]+:)*(?:m|mx|my|mt|mr|mb|ml|ms|me)-/;
const interactiveStateUtilities = new Set(["hover", "focus", "focus-visible", "active"]);
const nonNeutralStateColor = /^(?:bg|text|border|ring|outline)-(?:blue|indigo|purple|brand|sky|violet|cyan|emerald|green|red|orange|yellow|pink|teal|amber|rose|fuchsia|lime|transparent)(?:-|$)/;

const triggerClasses = (className: string, isOptionsApi: boolean) => {
  const triggerClassName = className
    .split(/\s+/)
    .filter((token) => {
      if (!token || marginUtilities.test(token)) return false;
      const [utility] = token.split(":").slice(-1);
      const hasInteractiveState = token.split(":").some((variant) => interactiveStateUtilities.has(variant));
      return !(hasInteractiveState && nonNeutralStateColor.test(utility));
    })
    .join(" ");

  return [
    "flex min-h-11 items-center justify-between gap-3 rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm text-gray-900 shadow-theme-xs transition-colors hover:bg-gray-100 focus:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:bg-gray-800 dark:focus:border-gray-400",
    isOptionsApi ? "w-full" : "",
    triggerClassName,
  ].filter(Boolean).join(" ");
};

const neutralOptionClass = "text-gray-900 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-800";
const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(props, forwardedRef) {
  const {
    options,
    placeholder = "Select an option",
    children,
    className = "",
    label,
    defaultValue,
    value,
    onChange,
    disabled = false,
    id,
    title,
    autoFocus,
    tabIndex,
    "aria-label": ariaLabel,
    "aria-labelledby": ariaLabelledBy,
    "aria-describedby": ariaDescribedBy,
    "aria-invalid": ariaInvalid,
    "aria-required": ariaRequired,
    onBlur,
    onFocus,
    onKeyDown: nativeOnKeyDown,
    onClick: nativeOnClick,
    ...nativeProps
  } = props;
  const isOptionsApi = options !== undefined;
  const childOptions = useMemo(() => optionsFromChildren(children), [children]);
  const menuOptions = isOptionsApi
    ? [{ value: "", label: placeholder }, ...options]
    : childOptions;
  const generatedId = useId();
  const triggerId = id ?? `${generatedId}-select-trigger`;
  const nativeId = id ? `${id}-native` : `${generatedId}-select-native`;
  const listboxId = `${generatedId}-select-listbox`;
  const nativeSelectRef = useRef<HTMLSelectElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const initialValue = defaultValue !== undefined
    ? asSelectValue(defaultValue)
    : isOptionsApi
      ? ""
      : childOptions.find((option) => option.value === "")?.value ?? childOptions[0]?.value ?? "";
  const [internalValue, setInternalValue] = useState(initialValue);
  const [isOpen, setIsOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState<number | null>(null);
  const selectedValue = value !== undefined ? asSelectValue(value) : internalValue;
  const selectedOption = menuOptions.find((option) => option.value === selectedValue);
  const firstEnabledIndex = menuOptions.findIndex((option) => !option.disabled);
  const selectedIndex = menuOptions.findIndex((option) => option.value === selectedValue && !option.disabled);
  const activeOptionIndex = activeIndex ?? (selectedIndex >= 0 ? selectedIndex : firstEnabledIndex);
  const preserveNativeSelect = !isOptionsApi && Boolean(nativeProps.name || nativeProps.form || nativeProps.required || nativeProps.multiple);

  const setNativeRef = (node: HTMLSelectElement | null) => {
    nativeSelectRef.current = node;
  };

  useImperativeHandle(forwardedRef, () => ({
    focus: () => triggerRef.current?.focus(),
  } as unknown as HTMLSelectElement), []);

  useEffect(() => {
    const handleOutsideClick = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setIsOpen(false);
    };
    document.addEventListener("mousedown", handleOutsideClick);
    return () => document.removeEventListener("mousedown", handleOutsideClick);
  }, []);

  const closeMenu = () => {
    setIsOpen(false);
    setActiveIndex(null);
  };

  const openMenu = () => {
    if (disabled) return;
    setActiveIndex(activeOptionIndex >= 0 ? activeOptionIndex : firstEnabledIndex >= 0 ? firstEnabledIndex : null);
    setIsOpen(true);
  };

  const handleNativeChange = (event: ChangeEvent<HTMLSelectElement>) => {
    setInternalValue(event.target.value);
    if (isOptionsApi) {
      (onChange as ((nextValue: string) => void) | undefined)?.(event.target.value);
    } else {
      (onChange as ChangeEventHandler<HTMLSelectElement> | undefined)?.(event);
    }
  };

  const choose = (nextValue: string) => {
    const nextOption = menuOptions.find((option) => option.value === nextValue);
    if (disabled || nextOption?.disabled) return;

    setInternalValue(nextValue);
    if (isOptionsApi) {
      (onChange as ((nextValue: string) => void) | undefined)?.(nextValue);
    } else {
      if (nativeSelectRef.current) nativeSelectRef.current.value = nextValue;
      const changeEvent = {
        target: { value: nextValue },
        currentTarget: { value: nextValue },
        nativeEvent: new Event("change", { bubbles: true }),
        bubbles: true,
        cancelable: false,
        defaultPrevented: false,
        isDefaultPrevented: () => false,
        isPropagationStopped: () => false,
        persist: () => undefined,
        preventDefault: () => undefined,
        stopPropagation: () => undefined,
        type: "change",
      } as unknown as ChangeEvent<HTMLSelectElement>;
      (onChange as ChangeEventHandler<HTMLSelectElement> | undefined)?.(changeEvent);
    }
    closeMenu();
    triggerRef.current?.focus();
  };

  useEffect(() => {
    const trigger = triggerRef.current;
    if (!trigger) return undefined;

    const handleTriggerChange = (event: Event) => {
      const nextValue = (event.target as HTMLButtonElement).value;
      choose(nextValue);
    };

    trigger.addEventListener("change", handleTriggerChange);
    return () => trigger.removeEventListener("change", handleTriggerChange);
  }, [menuOptions, selectedValue, disabled, isOptionsApi, onChange]);

  const moveActiveOption = (direction: 1 | -1) => {
    if (!menuOptions.length) return;
    let nextIndex = activeOptionIndex;
    for (let step = 0; step < menuOptions.length; step += 1) {
      nextIndex = (nextIndex + direction + menuOptions.length) % menuOptions.length;
      if (!menuOptions[nextIndex]?.disabled) {
        setActiveIndex(nextIndex);
        return;
      }
    }
  };

  const handleTriggerKeyDown: KeyboardEventHandler<HTMLButtonElement> = (event) => {
    (nativeOnKeyDown as KeyboardEventHandler<HTMLButtonElement> | undefined)?.(event);
    if (event.defaultPrevented || disabled) return;

    if (event.key === "ArrowDown") {
      event.preventDefault();
      if (!isOpen) openMenu();
      else moveActiveOption(1);
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      if (!isOpen) openMenu();
      else moveActiveOption(-1);
    } else if (event.key === "Home" && isOpen) {
      event.preventDefault();
      setActiveIndex(firstEnabledIndex >= 0 ? firstEnabledIndex : null);
    } else if (event.key === "End" && isOpen) {
      event.preventDefault();
      for (let index = menuOptions.length - 1; index >= 0; index -= 1) {
        if (!menuOptions[index]?.disabled) {
          setActiveIndex(index);
          break;
        }
      }
    } else if ((event.key === "Enter" || event.key === " ") && isOpen) {
      event.preventDefault();
      const activeOption = menuOptions[activeOptionIndex];
      if (activeOption) choose(activeOption.value);
    } else if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      openMenu();
    } else if (event.key === "Escape" && isOpen) {
      event.preventDefault();
      closeMenu();
    } else if (event.key === "Tab") {
      closeMenu();
    }
  };

  const handleTriggerFocus: FocusEventHandler<HTMLButtonElement> = (event) => {
    (onFocus as FocusEventHandler<HTMLButtonElement> | undefined)?.(event);
  };

  const handleTriggerBlur: FocusEventHandler<HTMLButtonElement> = (event) => {
    (onBlur as FocusEventHandler<HTMLButtonElement> | undefined)?.(event);
  };

  const handleTriggerChange: ChangeEventHandler<HTMLButtonElement> = (event) => {
    const nextValue = event.target.value;
    if (menuOptions.some((option) => option.value === nextValue)) choose(nextValue);
  };

  const optionClass = (option: Option, index: number) => {
    const isSelected = selectedValue === option.value;
    const isHighlighted = activeOptionIndex === index;
    const placeholderClass = selectedValue === "" ? "bg-gray-950 text-white dark:bg-gray-950 dark:text-white" : neutralOptionClass;
    const selectedClass = option.value === "" ? placeholderClass : selectedValue === option.value ? "bg-gray-950 text-white dark:bg-gray-950 dark:text-white" : neutralOptionClass;
    return `${selectedClass} ${isHighlighted && !isSelected ? "bg-gray-100 dark:bg-gray-800" : ""} ${option.disabled ? "cursor-not-allowed opacity-50" : ""}`;
  };

  return (
    <div ref={containerRef} className={`relative inline-block align-top ${className}`}>
      {label && (
        <label htmlFor={triggerId} className="mb-2 block text-sm font-semibold text-gray-700 dark:text-gray-300">
          {label}
        </label>
      )}
      <button
        id={triggerId}
        ref={triggerRef}
        type="button"
        role="combobox"
        aria-expanded={isOpen}
        aria-haspopup="listbox"
        aria-controls={listboxId}
        aria-activedescendant={isOpen && activeOptionIndex >= 0 ? `${listboxId}-option-${activeOptionIndex}` : undefined}
        aria-label={ariaLabel}
        aria-labelledby={ariaLabelledBy}
        aria-describedby={ariaDescribedBy}
        aria-invalid={ariaInvalid}
        aria-required={ariaRequired}
        title={title}
        autoFocus={autoFocus}
        tabIndex={tabIndex}
        value={selectedValue}
        disabled={disabled}
        onFocus={handleTriggerFocus}
        onBlur={handleTriggerBlur}
        onKeyDown={handleTriggerKeyDown}
        onChange={handleTriggerChange}
        onClick={(event) => {
          (nativeOnClick as MouseEventHandler<HTMLButtonElement> | undefined)?.(event);
          if (event.defaultPrevented) return;
          if (isOpen) closeMenu();
          else openMenu();
        }}
        className={triggerClasses(className, isOptionsApi)}
      >
        <div className={selectedOption ? "truncate" : "truncate text-gray-400"}>
          {selectedOption?.label ?? placeholder}
        </div>
        <ChevronDown aria-hidden="true" size={16} className={`shrink-0 text-gray-500 transition-transform ${isOpen ? "rotate-180" : ""}`} />
      </button>

      {(
        <div
          id={listboxId}
          role="listbox"
          aria-label={isOpen ? (ariaLabel ?? placeholder) : undefined}
          data-state={isOpen ? "open" : "closed"}
          className={isOpen
            ? "absolute z-50 mt-1 max-h-60 w-full min-w-full overflow-auto rounded-lg border border-gray-300 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
            : "absolute left-0 top-full z-50 h-0 w-full min-w-full overflow-hidden opacity-0 pointer-events-none"
          }
        >
          {menuOptions.map((option, index) => (
            <button
              id={`${listboxId}-option-${index}`}
              key={`${option.value}-${index}`}
              type="button"
              role="option"
              aria-selected={selectedValue === option.value}
              aria-disabled={option.disabled || undefined}
              disabled={option.disabled}
              tabIndex={-1}
              data-highlighted={activeOptionIndex === index || undefined}
              onMouseEnter={() => setActiveIndex(index)}
              onClick={() => choose(option.value)}
              className={`block w-full rounded-md px-3 py-2 text-left text-sm ${optionClass(option, index)}`}
            >
              {option.label}
            </button>
          ))}
        </div>
      )}

      {preserveNativeSelect && (
        <select
          {...nativeProps}
          id={nativeId}
          ref={setNativeRef}
          value={selectedValue}
          disabled={disabled}
          aria-hidden="true"
          tabIndex={-1}
          className="sr-only"
          onChange={handleNativeChange}
        >
          {menuOptions.map((option, index) => (
            <option
              key={`${option.value}-${index}`}
              value={option.value}
              disabled={option.disabled}
              aria-label={textFromNode(option.label)}
            />
          ))}
        </select>
      )}
      {!isOptionsApi && !preserveNativeSelect && (
        <input
          type="text"
          tabIndex={-1}
          aria-hidden="true"
          readOnly
          value={textFromNode(selectedOption?.label) || placeholder}
          className="sr-only"
        />
      )}
    </div>
  );
});

export default Select;
