import { useEffect, useRef, useState } from "react";

interface Option { value: string; label: string; }
interface SelectProps {
  options: Option[];
  placeholder?: string;
  onChange: (value: string) => void;
  className?: string;
  defaultValue?: string;
  value?: string;
  id?: string;
  "aria-label"?: string;
}

const Select: React.FC<SelectProps> = ({ options, placeholder = "Select an option", onChange, className = "", defaultValue = "", value, id, "aria-label": ariaLabel }) => {
  const [internalValue, setInternalValue] = useState(defaultValue);
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const selectedValue = value ?? internalValue;
  const selectedOption = options.find((option) => option.value === selectedValue);

  useEffect(() => {
    const handleOutsideClick = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setIsOpen(false);
    };
    document.addEventListener("mousedown", handleOutsideClick);
    return () => document.removeEventListener("mousedown", handleOutsideClick);
  }, []);

  const choose = (nextValue: string) => {
    setInternalValue(nextValue);
    onChange(nextValue);
    setIsOpen(false);
  };

  return <div ref={containerRef} className={`relative ${className}`}>
    <select id={id} aria-label={ariaLabel} value={selectedValue} onChange={(event) => choose(event.target.value)} tabIndex={-1} className="sr-only">
      <option value="">{placeholder}</option>
      {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
    </select>
    <button type="button" role="combobox" aria-expanded={isOpen} aria-haspopup="listbox" onClick={() => setIsOpen((open) => !open)} className={`flex h-11 w-full items-center justify-between rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-left text-sm shadow-theme-xs transition-colors hover:bg-gray-100 focus:border-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-900/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:hover:bg-gray-800 dark:focus:border-gray-300 ${selectedOption ? "text-gray-900 dark:text-white" : "text-gray-400"}`}>
      <span>{selectedOption?.label ?? placeholder}</span><span aria-hidden="true" className={`ml-3 text-gray-500 transition-transform ${isOpen ? "rotate-180" : ""}`}>⌄</span>
    </button>
    {isOpen && <div role="listbox" aria-label={ariaLabel ?? placeholder} className="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-300 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
      <button type="button" role="option" aria-selected={selectedValue === ""} onClick={() => choose("")} className={`block w-full rounded-md px-3 py-2 text-left text-sm ${selectedValue === "" ? "bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-white" : "text-gray-900 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-800"}`}>{placeholder}</button>
      {options.map((option) => <button type="button" role="option" aria-selected={selectedValue === option.value} key={option.value} onClick={() => choose(option.value)} className={`block w-full rounded-md px-3 py-2 text-left text-sm ${selectedValue === option.value ? "bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-white" : "text-gray-900 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-800"}`}>{option.label}</button>)}
    </div>}
  </div>;
};

export default Select;
