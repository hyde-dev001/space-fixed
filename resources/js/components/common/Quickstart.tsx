import React, { useState } from "react";

interface QuickstartStep {
  title: string;
  description: string;
  icon?: React.ReactNode;
}

interface QuickstartProps {
  title: string;
  steps: QuickstartStep[];
  onDismiss?: () => void;
}

const Quickstart: React.FC<QuickstartProps> = ({ title, steps, onDismiss }) => {
  const [expandedStep, setExpandedStep] = useState(0);
  const [isDismissed, setIsDismissed] = useState(false);

  if (isDismissed) return null;

  const handleDismiss = () => {
    setIsDismissed(true);
    onDismiss?.();
  };

  return (
    <div className="mb-6 p-6 bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 rounded-lg shadow-sm">
      <div className="flex items-start justify-between gap-4">
        <div className="flex-1">
          <h3 className="text-lg font-bold text-blue-900 dark:text-blue-200 mb-1">🚀 {title}</h3>
          <p className="text-sm text-blue-700 dark:text-blue-300 mb-4">Quick start guide to help you get productive</p>

          <div className="space-y-2">
            {steps.map((step, idx) => (
              <div key={idx} className="bg-white dark:bg-gray-800 rounded-lg overflow-hidden">
                <button
                  onClick={() => setExpandedStep(expandedStep === idx ? -1 : idx)}
                  className="w-full px-4 py-3 flex items-center gap-3 hover:bg-blue-50 dark:hover:bg-gray-700 transition-colors text-left"
                  aria-expanded={expandedStep === idx}
                >
                  <span className="flex items-center justify-center w-6 h-6 bg-blue-500 text-white rounded-full text-xs font-bold">
                    {idx + 1}
                  </span>
                  <span className="font-medium text-gray-900 dark:text-white">{step.title}</span>
                  <span className="ml-auto text-gray-400">
                    {expandedStep === idx ? "−" : "+"}
                  </span>
                </button>

                {expandedStep === idx && (
                  <div className="px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-blue-50 dark:bg-gray-900/50">
                    <p className="text-sm text-gray-700 dark:text-gray-300">{step.description}</p>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        <button
          onClick={handleDismiss}
          className="text-blue-500 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-bold text-lg"
          aria-label="Dismiss quickstart guide"
        >
          ✕
        </button>
      </div>
    </div>
  );
};

export default Quickstart;
