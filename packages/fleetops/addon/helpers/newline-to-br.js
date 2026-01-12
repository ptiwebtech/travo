// app/helpers/newline-to-br.js
import { helper } from '@ember/component/helper';
import { htmlSafe } from '@ember/template';

export function newlineToBr([text]) {
  // Replace newlines with <br> tags
  const formattedText = text.replace(/\n/g, '<br>');
  return htmlSafe(formattedText);  // Mark the result as safe HTML
}
export default helper(newlineToBr);
