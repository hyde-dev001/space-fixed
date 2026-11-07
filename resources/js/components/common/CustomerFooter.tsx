import { Link } from '@inertiajs/react';
import { forwardRef, useCallback, useEffect, useRef, useState, type ReactNode, type Ref } from 'react';

const FOOTER_GROUPS: Array<{
  title: string;
  links: Array<[label: string, href: string]>;
}> = [
  {
    title: 'Explore',
    links: [
      ['New releases', '/products'],
      ['Shop by category', '/products'],
      ['Book a repair', '/repair-services'],
    ],
  },
  {
    title: 'Support',
    links: [
      ['Our story', '/#landing-story'],
      ['Care services', '/services'],
      ['Contact support', '/services'],
    ],
  },
  {
    title: 'Community',
    links: [
      ['Join the community', '/#landing-community'],
      ['Shop SoleSpace', '/products'],
      ['Join our team', '/shop-owner-register'],
    ],
  },
];

type CustomerFooterProps = {
  className?: string;
  fixed?: boolean;
  interactive?: boolean;
};

const CustomerFooter = forwardRef<HTMLElement, CustomerFooterProps>(function CustomerFooter(
  { className = '', fixed = false, interactive = false },
  forwardedRef: Ref<HTMLElement>,
) {
  const footerRef = useRef<HTMLElement | null>(null);
  const [isVisible, setIsVisible] = useState(false);

  const setFooterRef = useCallback((element: HTMLElement | null) => {
    footerRef.current = element;

    if (typeof forwardedRef === 'function') {
      forwardedRef(element);
    } else if (forwardedRef) {
      (forwardedRef as { current: HTMLElement | null }).current = element;
    }
  }, [forwardedRef]);

  useEffect(() => {
    if (fixed) {
      return;
    }

    const footer = footerRef.current;
    if (!footer || typeof IntersectionObserver === 'undefined') {
      setIsVisible(true);
      return;
    }

    const observer = new IntersectionObserver(([entry]) => {
      if (entry?.isIntersecting) {
        setIsVisible(true);
        observer.disconnect();
      }
    }, { threshold: 0.08 });

    observer.observe(footer);
    return () => observer.disconnect();
  }, [fixed]);

  useEffect(() => {
    if (!fixed) {
      return;
    }

    footerRef.current?.toggleAttribute('inert', !interactive);
  }, [fixed, interactive]);

  return (
    <footer
      ref={setFooterRef}
      aria-label="SoleSpace footer"
      aria-hidden={fixed ? !interactive : undefined}
      className={`customer-footer ${fixed ? 'customer-footer--fixed customer-footer--visible' : isVisible ? 'customer-footer--visible' : ''} ${fixed && interactive ? 'customer-footer--interactive' : ''} ${className}`}
    >
      <div className="customer-footer__content">
        <div className="customer-footer__desktop-grid">
          <div><Link href="/" className="customer-footer__brand">SoleSpace</Link></div>
          <div>
            <h2>Explore</h2>
            <ul>
              <li><Link href="/products">New releases</Link></li>
              <li><Link href="/products">Shop by category</Link></li>
              <li><Link href="/repair-services">Book a repair</Link></li>
            </ul>
          </div>
          <div>
            <h2>Support</h2>
            <ul>
              <li><Link href="/#landing-story">Our story</Link></li>
              <li><Link href="/services">Care services</Link></li>
              <li><Link href="/services">Contact support</Link></li>
            </ul>
          </div>
          <div>
            <h2>Community</h2>
            <ul>
              <li><Link href="/#landing-community">Join the community</Link></li>
              <li><Link href="/products">Shop SoleSpace</Link></li>
              <li><Link href="/shop-owner-register">Join our team</Link></li>
            </ul>
          </div>
        </div>

        <div className="customer-footer__mobile-links">
          <p className="customer-footer__brand">SoleSpace</p>
          {FOOTER_GROUPS.map(({ title, links }) => (
            <details key={title}>
              <summary>{title}<span aria-hidden="true">+</span></summary>
              <div>
                {links.map(([label, href]) => <Link key={label} href={href}>{label}</Link>)}
              </div>
            </details>
          ))}
        </div>

        <div className="customer-footer__meta">
          <span>Copyright © 2024 SoleSpace</span>
          <span>Shipping to › Philippines</span>
          <span>Language › English</span>
        </div>
      </div>
      <div aria-hidden="true" className="customer-footer__wordmark">SOLESPACE</div>
    </footer>
  );
});

CustomerFooter.displayName = 'CustomerFooter';

export default CustomerFooter;

type CustomerFooterRevealProps = {
  children: ReactNode;
  className?: string;
};

export function CustomerFooterReveal({ children, className = '' }: CustomerFooterRevealProps) {
  const shellRef = useRef<HTMLDivElement | null>(null);
  const spacerRef = useRef<HTMLDivElement | null>(null);
  const footerRef = useRef<HTMLElement | null>(null);
  const [footerIsInteractive, setFooterIsInteractive] = useState(false);

  useEffect(() => {
    const shell = shellRef.current;
    const footer = footerRef.current;
    const spacer = spacerRef.current;

    if (!shell || !footer || !spacer) {
      return;
    }

    const updateFooterHeight = () => {
      const footerHeight = Math.ceil(footer.getBoundingClientRect().height);
      shell.style.setProperty('--customer-footer-height', `${footerHeight}px`);
    };

    updateFooterHeight();

    const resizeObserver = typeof ResizeObserver === 'undefined'
      ? null
      : new ResizeObserver(updateFooterHeight);

    resizeObserver?.observe(footer);

    if (typeof IntersectionObserver === 'undefined') {
      setFooterIsInteractive(true);

      return () => {
        resizeObserver?.disconnect();
        shell.style.removeProperty('--customer-footer-height');
      };
    }

    const revealObserver = new IntersectionObserver(
      ([entry]) => setFooterIsInteractive(entry?.isIntersecting ?? false),
      { threshold: 0 },
    );

    revealObserver.observe(spacer);

    return () => {
      revealObserver.disconnect();
      resizeObserver?.disconnect();
      shell.style.removeProperty('--customer-footer-height');
    };
  }, []);

  return (
    <div ref={shellRef} className={`customer-footer-page ${className}`}>
      <div className="customer-footer-page__curtain">
        {children}
      </div>
      <div ref={spacerRef} aria-hidden="true" className="customer-footer-page__spacer" />
      <CustomerFooter ref={footerRef} fixed interactive={footerIsInteractive} />
    </div>
  );
}
