/*
 * Laya — on-device category suggester for the MemoryDown admin UI.
 *
 * Runs a small multilingual embedding model (transformers.js + ONNX Runtime
 * WebAssembly) entirely in the browser. Nothing is sent to a server.
 *
 * The runtime and model are self-hosted under /assets/laya/ (see
 * tools/fetch-laya.php); env is configured for same-origin, single-threaded
 * WASM so no COOP/COEP headers are required and the strict admin CSP only
 * needs 'wasm-unsafe-eval'.
 *
 * This module is loaded lazily (dynamic import) on first use, so none of the
 * large assets are fetched until the user asks for a suggestion.
 */

const RUNTIME_URL = '/assets/laya/runtime/transformers.min.js';
const MODEL_ID = 'Xenova/multilingual-e5-small';
const LOCAL_MODEL_PATH = '/assets/laya/models/';
const WASM_PATH = '/assets/laya/runtime/';

// Cap the text handed to the model; category signals live near the top.
const MAX_CHARS = 1000;

let extractorPromise = null;
let prototypesPromise = null;

// A test/fallback hook: if present, it answers directly and no model is loaded.
function stub() {
  return typeof window.__layaStub === 'function' ? window.__layaStub : null;
}

function getExtractor() {
  if (!extractorPromise) {
    extractorPromise = (async () => {
      const mod = await import(RUNTIME_URL);
      const { env, pipeline } = mod;
      env.allowRemoteModels = false;
      env.allowLocalModels = true;
      env.useBrowserCache = true;
      env.localModelPath = LOCAL_MODEL_PATH;
      env.backends.onnx.wasm.wasmPaths = WASM_PATH;
      env.backends.onnx.wasm.numThreads = 1;
      env.backends.onnx.wasm.proxy = false;

      return pipeline('feature-extraction', MODEL_ID, { dtype: 'q8', device: 'wasm' });
    })();
  }

  return extractorPromise;
}

async function embed(extractor, text) {
  const out = await extractor(text, { pooling: 'mean', normalize: true });

  return out.data;
}

function dot(a, b) {
  let sum = 0;
  for (let i = 0; i < a.length; i += 1) {
    sum += a[i] * b[i];
  }

  return sum;
}

function getPrototypes(extractor, categories) {
  if (!prototypesPromise) {
    // Each category gets several anchor vectors (description + bilingual
    // keywords). A category scores as the best match to any anchor, which is
    // more robust than concatenating everything into one diluted vector.
    prototypesPromise = Promise.all(categories.map(async (category) => {
      const anchors = anchorTexts(category);
      const vecs = await Promise.all(anchors.map((t) => embed(extractor, 'query: ' + t)));

      return { slug: category.slug, vecs };
    }));
  }

  return prototypesPromise;
}

function anchorTexts(category) {
  const texts = [];
  if (category.description) { texts.push(category.description); }
  if (category.keywords) { texts.push(category.keywords); }
  if (0 === texts.length) { texts.push(category.slug); }

  return texts;
}

/**
 * Suggest the best category for `text`.
 *
 * @param {string} text
 * @param {Array<{slug: string, description: string, keywords?: string}>} categories
 * @returns {Promise<{slug: string, score: number}|null>}
 */
export async function suggestCategory(text, categories) {
  const hook = stub();
  if (hook) {
    const result = await hook(text, categories);
    if (typeof result === 'string') {
      return { slug: result, score: 1 };
    }

    return result || null;
  }

  const clean = String(text || '').slice(0, MAX_CHARS).trim();
  if ('' === clean || !Array.isArray(categories) || 0 === categories.length) {
    return null;
  }

  const extractor = await getExtractor();
  const [prototypes, query] = await Promise.all([
    getPrototypes(extractor, categories),
    embed(extractor, 'query: ' + clean),
  ]);

  let best = null;
  for (const prototype of prototypes) {
    let score = -Infinity;
    for (const vec of prototype.vecs) {
      const s = dot(query, vec);
      if (s > score) {
        score = s;
      }
    }
    if (!best || score > best.score) {
      best = { slug: prototype.slug, score };
    }
  }

  return best;
}

export function reset() {
  prototypesPromise = null;
}
