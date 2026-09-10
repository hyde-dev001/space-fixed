interface PasswordRequirementsProps {
  password: string;
}

export default function PasswordRequirements({ password }: PasswordRequirementsProps) {
  const requirements = [
    { met: password.length >= 8, text: "At least 8 characters" },
    { met: /[A-Z]/.test(password), text: "One uppercase letter (A-Z)" },
    { met: /[a-z]/.test(password), text: "One lowercase letter (a-z)" },
    { met: /[0-9]/.test(password), text: "One number (0-9)" },
    { met: /[^A-Za-z0-9\s]/.test(password), text: "One special character" },
  ];

  return (
    <div className="mt-2 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
      <p className="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-300">
        Password must contain:
      </p>
      <ul className="space-y-1" aria-label="Password requirements">
        {requirements.map((requirement) => (
          <li
            key={requirement.text}
            data-met={requirement.met}
            className={`flex items-center text-xs ${
              requirement.met
                ? "text-green-600 dark:text-green-400"
                : "text-gray-600 dark:text-gray-400"
            }`}
          >
            <span className="mr-2" aria-hidden="true">
              {requirement.met ? "✓" : "○"}
            </span>
            {requirement.text}
          </li>
        ))}
      </ul>
    </div>
  );
}
