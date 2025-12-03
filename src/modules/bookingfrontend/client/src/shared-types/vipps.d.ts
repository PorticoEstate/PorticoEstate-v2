declare namespace JSX {
  interface IntrinsicElements {
    'vipps-checkout-button': React.DetailedHTMLProps<React.HTMLAttributes<HTMLElement>, HTMLElement> & {
      disabled?: boolean;
      onClick?: (event: Event) => void;
      onclick?: (event: Event) => void;
      type?: 'button' | 'submit';
      brand?: 'vipps' | 'mobilepay';
      language?: 'no' | 'en' | 'da' | 'fi';
      variant?: 'primary' | 'secondary' | 'outline' | 'ghost';
      rounded?: 'true' | 'false';
      verb?: 'continue' | 'pay' | 'buy' | 'order' | 'book' | 'donate' | 'subscribe';
      stretched?: 'true' | 'false';
      branded?: 'true' | 'false';
      loading?: 'true' | 'false';
      'vmp-continue-as-first-name'?: string;
    };
  }
}