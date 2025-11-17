import { mermaid, type Mermaid } from 'mermaid';

declare global {
  interface Window {
    mermaid: Mermaid;
  }
}

// this is a type declaration file, not a source file
// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment
window.mermaid = mermaid;
