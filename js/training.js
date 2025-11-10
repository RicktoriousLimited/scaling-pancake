const stackSelect = document.getElementById('stack-select');
const stackMeta = document.getElementById('stack-meta');
const stackList = document.getElementById('stack-list');
const samplesInput = document.getElementById('samples-input');
const loadSamplesBtn = document.getElementById('load-samples');
const formatJsonBtn = document.getElementById('format-json');
const trainingForm = document.getElementById('training-form');
const trainingStatus = document.getElementById('training-status');
const validationStatus = document.getElementById('validation-status');
const submitBtn = document.getElementById('submit-btn');

let stackConfig = {};

const prettifyJSON = (value) => {
  try {
    const parsed = JSON.parse(value);
    return JSON.stringify(parsed, null, 2);
  } catch (error) {
    return value.trim();
  }
};

const renderStackMeta = (stackName) => {
  const meta = stackConfig[stackName];
  if (!meta) {
    stackMeta.innerHTML = '<span>Stack configuration unavailable.</span>';
    return;
  }

  stackMeta.innerHTML = `
    <div>
      <strong>Inputs</strong>
      <div>${meta.inputSize}</div>
    </div>
    <div>
      <strong>Outputs</strong>
      <div>${meta.outputSize}</div>
    </div>
    <div>
      <strong>Learning rate</strong>
      <div>${meta.learningRate}</div>
    </div>
    <div>
      <strong>Decay</strong>
      <div>${meta.decay}</div>
    </div>
  `;
};

const renderStackCards = () => {
  stackList.innerHTML = '';
  Object.entries(stackConfig).forEach(([name, meta]) => {
    const card = document.createElement('div');
    card.className = 'stack-card';
    card.innerHTML = `
      <h3>${name.toUpperCase()}</h3>
      <span><strong>Inputs:</strong> ${meta.inputSize}</span>
      <span><strong>Outputs:</strong> ${meta.outputSize}</span>
      <span><strong>Learning rate:</strong> ${meta.learningRate}</span>
      <span><strong>Decay:</strong> ${meta.decay}</span>
    `;
    stackList.appendChild(card);
  });
};

const setStatus = (message, type = 'info') => {
  trainingStatus.textContent = message;
  trainingStatus.style.color = type === 'error' ? '#ff7a99' : '#d7e3ff';
};

const setValidation = (message, type = 'info') => {
  validationStatus.textContent = message;
  validationStatus.style.color = type === 'error' ? '#ff7a99' : '#7cd6ff';
};

const fetchConfig = async () => {
  try {
    const response = await fetch('data/config.json');
    const data = await response.json();
    stackConfig = data.stacks || {};

    stackSelect.innerHTML = '';
    Object.keys(stackConfig).forEach((name) => {
      const option = document.createElement('option');
      option.value = name;
      option.textContent = name;
      stackSelect.appendChild(option);
    });

    if (stackSelect.value) {
      renderStackMeta(stackSelect.value);
    }

    renderStackCards();
  } catch (error) {
    stackSelect.innerHTML = '';
    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'Failed to load configuration';
    stackSelect.appendChild(option);
    setStatus(`Unable to load stack configuration: ${error}`, 'error');
  }
};

const loadSampleBatch = async () => {
  const stack = stackSelect.value;
  if (!stack) return;

  try {
    const response = await fetch(`data/training_${stack}.json`);
    if (!response.ok) {
      throw new Error('No sample batch found for this stack.');
    }
    const data = await response.json();
    samplesInput.value = JSON.stringify(data, null, 2);
    setValidation(`Loaded ${Array.isArray(data) ? data.length : 0} samples for ${stack}.`);
  } catch (error) {
    setValidation(`Unable to load samples: ${error.message}`, 'error');
  }
};

const validateSamples = (samples, stackName) => {
  if (!Array.isArray(samples)) {
    throw new Error('Samples payload must be an array.');
  }

  const meta = stackConfig[stackName];
  if (!meta) {
    return;
  }

  samples.forEach((sample, index) => {
    if (typeof sample !== 'object' || sample === null) {
      throw new Error(`Sample at index ${index} is not an object.`);
    }

    if (!Array.isArray(sample.input)) {
      throw new Error(`Sample ${index} is missing an input array.`);
    }

    if (!Array.isArray(sample.target)) {
      throw new Error(`Sample ${index} is missing a target array.`);
    }

    if (sample.input.length !== meta.inputSize) {
      throw new Error(`Sample ${index} input length (${sample.input.length}) does not match stack requirement (${meta.inputSize}).`);
    }

    if (sample.target.length !== meta.outputSize) {
      throw new Error(`Sample ${index} target length (${sample.target.length}) does not match stack requirement (${meta.outputSize}).`);
    }
  });
};

stackSelect.addEventListener('change', () => {
  renderStackMeta(stackSelect.value);
  setValidation('Stack changed. Validate your payload before sending.');
});

loadSamplesBtn.addEventListener('click', () => {
  loadSampleBatch();
});

formatJsonBtn.addEventListener('click', () => {
  samplesInput.value = prettifyJSON(samplesInput.value);
  setValidation('Attempted to format JSON payload.');
});

trainingForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const stack = stackSelect.value;
  const payload = samplesInput.value.trim();

  if (!payload) {
    setValidation('Provide at least one sample to train.', 'error');
    return;
  }

  try {
    const parsed = JSON.parse(payload);
    validateSamples(parsed, stack);
    setValidation(`Payload validated with ${parsed.length} samples for ${stack}.`);

    submitBtn.disabled = true;
    setStatus('Submitting training batch…');

    const response = await fetch('api/train.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ stack, samples: parsed })
    });

    const data = await response.json();

    if (!response.ok) {
      throw new Error(data.error || 'Training failed.');
    }

    setStatus(`Training successful. Updated with ${data.trained} sample(s).`);
  } catch (error) {
    setStatus(`Error: ${error.message}`, 'error');
    setValidation('Resolve the highlighted error and retry.', 'error');
  } finally {
    submitBtn.disabled = false;
  }
});

fetchConfig();
