# Security Guidance Plugin for Claude Code

This plugin provides security reminders and warnings when editing files to help prevent common security vulnerabilities.

## Overview

The security-guidance plugin monitors file editing operations (Edit, Write, MultiEdit) and warns you about potential security issues before the changes are applied. It helps catch common security anti-patterns including:

- Command injection vulnerabilities
- Cross-site scripting (XSS) attacks
- Unsafe code evaluation
- Insecure deserialization
- Unsafe system calls

## Installation

This plugin has been installed in your project at:
```
c:\xampp\htdocs\solespace-master\plugins\security-guidance\
```

Claude Code will automatically detect and load this plugin when running in this project directory.

## How It Works

The plugin uses a PreToolUse hook that runs before any file editing operations. It:

1. Analyzes the file path and content being edited
2. Checks against a list of security patterns
3. Displays a warning if a potential security issue is detected
4. Tracks shown warnings per session to avoid duplicate alerts

## Security Patterns Detected

### GitHub Actions Workflow Files
- **Files**: `.github/workflows/*.yml`, `.github/workflows/*.yaml`
- **Risk**: Command injection in workflow files
- **Guidance**: Use environment variables instead of direct GitHub context interpolation

### JavaScript/TypeScript
- `child_process.exec()` - Command injection risk
- `eval()` - Arbitrary code execution
- `new Function()` - Code injection
- `document.write` - XSS and performance issues
- `.innerHTML` - XSS vulnerabilities
- `dangerouslySetInnerHTML` - XSS in React components

### Python
- `pickle` - Arbitrary code execution via deserialization
- `os.system` - Command injection risk

## Configuration

### Disabling the Plugin

To temporarily disable security reminders, set the environment variable:
```bash
export ENABLE_SECURITY_REMINDER=0
```

To re-enable:
```bash
export ENABLE_SECURITY_REMINDER=1
```

### State Management

The plugin maintains session-specific state files at:
```
~/.claude/security_warnings_state_<session_id>.json
```

These files track which warnings have been shown in each session to prevent duplicate alerts. State files older than 30 days are automatically cleaned up.

## Requirements

- Python 3.x (python3 command must be available)
- Claude Code with plugin support

## Author

**David Dworken** (dworken@anthropic.com)

## Version

1.0.0

## License

This plugin is part of the official Claude Code plugin collection maintained by Anthropic.
