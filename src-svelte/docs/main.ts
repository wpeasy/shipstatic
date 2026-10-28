import '@wpeasy/ab-ui/styles';
import '@wpeasy/ab-ui/styles/wp-admin.css';
import '../shared/app.css';
import { mount } from 'svelte';
import Docs from './Docs.svelte';

const target = document.getElementById('bs-docs');

if (target) {
  // Mount first, then drop the server-rendered placeholder (the "Loading…" /
  // noscript fallback, which also carries the startup watchdog's #bs-loading).
  // If mount() throws, the placeholder stays and the watchdog explains why.
  mount(Docs, { target });
  document.getElementById('bs-loading')?.remove();
  target.querySelector(':scope > noscript')?.remove();
}
