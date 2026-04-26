export class CaptchaChallenge {
  constructor(uuid, bigImage, smallImage, height, clientUid = '') {
    this.uuid = uuid;
    this.bigImage = bigImage;
    this.smallImage = smallImage;
    this.height = height;
    this.clientUid = clientUid;
  }
}
