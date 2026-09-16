export class TerritoryBootstrap {
  #controller = null;
  #generation = 0;

  constructor({ loadTerritories, view, onReady = () => {} }) {
    this.loadTerritories = loadTerritories;
    this.view = view;
    this.onReady = onReady;
    this.isReady = false;
  }

  async load() {
    this.#controller?.abort();
    const generation = ++this.#generation;
    const controller = new AbortController();
    this.#controller = controller;
    this.isReady = false;
    this.view.showTerritoryLoading();

    try {
      const collection = await this.loadTerritories({ signal: controller.signal });
      if (controller.signal.aborted || generation !== this.#generation) return false;

      this.view.publishTerritories(collection);
      this.isReady = true;
      this.onReady();
      return true;
    } catch (error) {
      if (isAbort(error) || controller.signal.aborted || generation !== this.#generation) {
        return false;
      }

      this.view.showTerritoryError(error, () => this.load());
      return false;
    }
  }

  dispose() {
    ++this.#generation;
    this.#controller?.abort();
  }
}

function isAbort(error) {
  return error?.name === 'AbortError';
}
