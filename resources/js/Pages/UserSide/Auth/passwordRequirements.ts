export const PASSWORD_REQUIREMENTS = [
  {
    key: 'minLength',
    label: 'At least 8 characters',
    test: (password: string) => password.length >= 8,
  },
  {
    key: 'uppercase',
    label: 'One uppercase letter',
    test: (password: string) => /[A-Z]/.test(password),
  },
  {
    key: 'lowercase',
    label: 'One lowercase letter',
    test: (password: string) => /[a-z]/.test(password),
  },
  {
    key: 'number',
    label: 'One number',
    test: (password: string) => /[0-9]/.test(password),
  },
] as const;

export const getPasswordRequirementState = (password: string) => (
  PASSWORD_REQUIREMENTS.map(({ key, label, test }) => ({
    key,
    label,
    met: test(password),
  }))
);

export const isPasswordValid = (password: string) => (
  PASSWORD_REQUIREMENTS.every(({ test }) => test(password))
);
