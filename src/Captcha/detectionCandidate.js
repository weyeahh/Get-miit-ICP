export class DetectionCandidate {
  constructor(method, rect, confidence, diagnostics = {}) {
    this.method = method;
    this.rect = rect;
    this.confidence = confidence;
    this.diagnostics = diagnostics;
  }
}
