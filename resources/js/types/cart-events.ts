// Cart Event Types
export interface CartAddedEventDetail {
  added?: number;
  total: number;
}

export interface CartGuestAddAttemptEventDetail {
  productId?: number | string;
}

export interface CartUpdatedEventDetail {
  itemId: string | number;
  quantity: number;
  total: number;
}

export interface CartRemovedEventDetail {
  itemId: string | number;
  total: number;
}

export interface CartClearedEventDetail {
  previousTotal: number;
}

// Event type exports
export type CartAddedEvent = CustomEvent<CartAddedEventDetail>;
export type CartGuestAddAttemptEvent = CustomEvent<CartGuestAddAttemptEventDetail>;
export type CartUpdatedEvent = CustomEvent<CartUpdatedEventDetail>;
export type CartRemovedEvent = CustomEvent<CartRemovedEventDetail>;
export type CartClearedEvent = CustomEvent<CartClearedEventDetail>;

// Event name constants
export const CART_EVENTS = {
  ADDED: 'cart:added',
  UPDATED: 'cart:updated',
  REMOVED: 'cart:removed',
  CLEARED: 'cart:cleared',
  GUEST_ADD_ATTEMPT: 'cart:guest:add-attempt',
} as const;

// Type-safe event dispatcher helpers
export const dispatchCartAddedEvent = (detail: CartAddedEventDetail): void => {
  window.dispatchEvent(new CustomEvent<CartAddedEventDetail>(CART_EVENTS.ADDED, { detail }));
};

export const dispatchCartGuestAddAttemptEvent = (detail: CartGuestAddAttemptEventDetail): void => {
  window.dispatchEvent(new CustomEvent<CartGuestAddAttemptEventDetail>(CART_EVENTS.GUEST_ADD_ATTEMPT, { detail }));
};

export const dispatchCartUpdatedEvent = (detail: CartUpdatedEventDetail): void => {
  window.dispatchEvent(new CustomEvent<CartUpdatedEventDetail>(CART_EVENTS.UPDATED, { detail }));
};

export const dispatchCartRemovedEvent = (detail: CartRemovedEventDetail): void => {
  window.dispatchEvent(new CustomEvent<CartRemovedEventDetail>(CART_EVENTS.REMOVED, { detail }));
};

export const dispatchCartClearedEvent = (detail: CartClearedEventDetail): void => {
  window.dispatchEvent(new CustomEvent<CartClearedEventDetail>(CART_EVENTS.CLEARED, { detail }));
};

// Type-safe event listener helpers
export const addCartAddedListener = (handler: (event: CartAddedEvent) => void): void => {
  window.addEventListener(CART_EVENTS.ADDED, handler as EventListener);
};

export const removeCartAddedListener = (handler: (event: CartAddedEvent) => void): void => {
  window.removeEventListener(CART_EVENTS.ADDED, handler as EventListener);
};

export const addCartGuestAddAttemptListener = (handler: (event: CartGuestAddAttemptEvent) => void): void => {
  window.addEventListener(CART_EVENTS.GUEST_ADD_ATTEMPT, handler as EventListener);
};

export const removeCartGuestAddAttemptListener = (handler: (event: CartGuestAddAttemptEvent) => void): void => {
  window.removeEventListener(CART_EVENTS.GUEST_ADD_ATTEMPT, handler as EventListener);
};

export const addCartUpdatedListener = (handler: (event: CartUpdatedEvent) => void): void => {
  window.addEventListener(CART_EVENTS.UPDATED, handler as EventListener);
};

export const removeCartUpdatedListener = (handler: (event: CartUpdatedEvent) => void): void => {
  window.removeEventListener(CART_EVENTS.UPDATED, handler as EventListener);
};

export const addCartRemovedListener = (handler: (event: CartRemovedEvent) => void): void => {
  window.addEventListener(CART_EVENTS.REMOVED, handler as EventListener);
};

export const removeCartRemovedListener = (handler: (event: CartRemovedEvent) => void): void => {
  window.removeEventListener(CART_EVENTS.REMOVED, handler as EventListener);
};

export const addCartClearedListener = (handler: (event: CartClearedEvent) => void): void => {
  window.addEventListener(CART_EVENTS.CLEARED, handler as EventListener);
};

export const removeCartClearedListener = (handler: (event: CartClearedEvent) => void): void => {
  window.removeEventListener(CART_EVENTS.CLEARED, handler as EventListener);
};
