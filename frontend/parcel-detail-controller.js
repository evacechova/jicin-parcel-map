export class ParcelDetailController {
  #controller = null;
  #generation = 0;
  #selection = null;

  constructor({ loadDetail, view }) {
    this.loadDetail = loadDetail;
    this.view = view;
  }

  select(selection) {
    this.#selection = selection;
    this.view.showDetailLoading(selection);
    return this.#request(selection);
  }

  retry() {
    if (this.#selection === null) return Promise.resolve(false);
    this.view.showDetailLoading(this.#selection);
    return this.#request(this.#selection);
  }

  close() {
    ++this.#generation;
    this.#controller?.abort();
    this.#selection = null;
    this.view.closeDetail();
  }

  async #request(selection) {
    this.#controller?.abort();
    const generation = ++this.#generation;
    const controller = new AbortController();
    this.#controller = controller;

    try {
      const detail = await this.loadDetail({
        inspireId: selection.id,
        signal: controller.signal,
      });
      if (controller.signal.aborted || generation !== this.#generation) return false;

      this.view.publishDetail(detail);
      return true;
    } catch (error) {
      if (isAbort(error) || controller.signal.aborted || generation !== this.#generation) {
        return false;
      }

      if (error?.code === 'parcel_not_found') {
        this.#selection = null;
        this.view.showParcelGone();
        return true;
      }

      this.view.showDetailError(error, () => this.retry());
      return false;
    }
  }
}

function isAbort(error) {
  return error?.name === 'AbortError';
}
