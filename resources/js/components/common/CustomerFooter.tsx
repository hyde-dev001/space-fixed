import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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

export default function CustomerFooter({ className = '' }: { className?: string }) {
  const footerRef = useRef<HTMLElement | null>(null);
  const [isVisible, setIsVisible] = useState(false);

  useEffect(() => {
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
  }, []);

  return (
    <footer ref={footerRef} aria-label="SoleSpace footer" className={`customer-footer ${isVisible ? 'customer-footer--visible' : ''} ${className}`}>
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
}
