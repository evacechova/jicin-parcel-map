import { VIEWPORT_DEBOUNCE_MS } from './config.js';

export class DebouncedViewportCoordinator {
  #timer = null;

  constructor({
    onSettled,
    delay = VIEWPORT_DEBOUNCE_MS,
    setTimer = (callback, milliseconds) => setTimeout(callback, milliseconds),
    clearTimer = (timer) => clearTimeout(timer),
  }) {
    this.onSettled = onSettled;
    this.delay = delay;
    this.setTimer = setTimer;
    this.clearTimer = clearTimer;
  }

  schedule() {
    if (this.#timer !== null) this.clearTimer(this.#timer);
    this.#timer = this.setTimer(() => this.flush(), this.delay);
  }

  flush() {
    if (this.#timer !== null) {
      this.clearTimer(this.#timer);
      this.#timer = null;
    }
    return this.onSettled();
  }

  dispose() {
    if (this.#timer !== null) this.clearTimer(this.#timer);
    this.#timer = null;
  }
}
