import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const repairSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/Repair.tsx'),
  'utf8',
);

describe('Repair catalog layout', () => {
  it('opts the catalog and dynamic shop cards into the shared scroll reveal', () => {
    expect(repairSource).toContain("import { useScrollReveal } from '../Shared/useScrollReveal';");
    expect(repairSource).toContain('const revealRootRef = useRef<HTMLDivElement | null>(null);');
    expect(repairSource).toContain('useScrollReveal(revealRootRef);');
    expect(repairSource).toContain('ref={revealRootRef}');
    expect(repairSource.match(/data-scroll-reveal/g)?.length ?? 0).toBeGreaterThanOrEqual(2);
    expect(repairSource).toContain('data-scroll-reveal className="scroll-reveal h-full"');
    expect(repairSource).toContain('className="group flex h-full');
  });
});
