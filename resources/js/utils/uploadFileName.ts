export function displayUploadFileName(fileName: string | undefined): string {
  const name = (fileName ?? '').replace(/[\u0000-\u001f\u007f]/g, '').trim();
  return /^(?:data:|blob:)/i.test(name) ? 'Selected document' : name;
}
