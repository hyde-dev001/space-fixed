import React from "react";

interface ErrorModalProps {
  title?: string;
  message: string;
  onClose: () => void;
  show?: boolean;
}

const ErrorModal: React.FC<ErrorModalProps> = ({ title = "Error", message, onClose, show = true }) => {
  if (!show) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
      <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-md w-full p-6 border border-red-200 dark:border-red-800">
        <h2 className="text-2xl font-bold text-red-700 dark:text-red-400 mb-2">{title}</h2>
        <p className="text-gray-700 dark:text-gray-300 mb-6">{message}</p>
        <button
          onClick={onClose}
          className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors w-full"
          aria-label="Close error dialog"
        >
          Close
        </button>
      </div>
    </div>
  );
};

export default ErrorModal;
