(() => {
  const respondSampleEl = document.getElementById('respond-sample');
  const trainSampleEl = document.getElementById('train-sample');
  const respondBtn = document.getElementById('run-respond');
  const trainBtn = document.getElementById('run-train');
  const refreshConfigBtn = document.getElementById('refresh-config');
  const respondStatus = document.getElementById('respond-status');
  const trainStatus = document.getElementById('train-status');
  const sampleStatus = document.getElementById('sample-status');
  const configStatus = document.getElementById('config-status');
  const respondOutput = document.getElementById('respond-output');
  const trainOutput = document.getElementById('train-output');
  const configOutput = document.getElementById('config-output');

  let samples = null;

  const formatJson = (value) => JSON.stringify(value, null, 2);

  const setStatus = (element, message, type = 'info') => {
    if (!element) {
      return;
    }
    element.textContent = message;
    if (type === 'error') {
      element.classList.add('error');
    } else {
      element.classList.remove('error');
    }
  };

  const flattenWeights = (stack) => {
    const container = [];
    if (!stack || typeof stack !== 'object' || !stack.regressors) {
      return container;
    }
    const keys = Object.keys(stack.regressors).sort((a, b) => Number(a) - Number(b));
    for (const key of keys) {
      const reg = stack.regressors[key];
      if (reg && Array.isArray(reg.weights)) {
        for (const weight of reg.weights) {
          const numeric = Number(weight);
          container.push(Number.isFinite(numeric) ? numeric : 0);
        }
      }
    }
    return container;
  };

  const summariseDelta = (before, after) => {
    const languageBefore = before?.stacks?.language;
    const languageAfter = after?.stacks?.language;
    if (!languageAfter) {
      return 'No language stack present in the refreshed configuration snapshot.';
    }
    const beforeWeights = flattenWeights(languageBefore);
    const afterWeights = flattenWeights(languageAfter);
    if (!afterWeights.length) {
      return 'Persisted regression weights were not available in the configuration snapshot.';
    }
    const previewCount = Math.min(5, afterWeights.length);
    const lines = [];
    for (let i = 0; i < previewCount; i += 1) {
      const beforeValue = beforeWeights[i] ?? 0;
      const afterValue = afterWeights[i];
      const delta = afterValue - beforeValue;
      const deltaSign = delta >= 0 ? '+' : '-';
      lines.push(
        `w${i + 1}: ${beforeValue.toFixed(5)} → ${afterValue.toFixed(5)} (Δ ${deltaSign}${Math.abs(delta).toFixed(5)})`,
      );
    }
    return lines.join('\n');
  };

  const buildConfigPreview = (config) => {
    if (!config || typeof config !== 'object' || !config.stacks) {
      return 'No stack definitions found in configuration.';
    }
    const summary = {};
    for (const [name, stack] of Object.entries(config.stacks)) {
      const entry = {
        inputSize: stack.inputSize,
        outputSize: stack.outputSize,
        decay: stack.decay,
      };
      if (Array.isArray(stack.state) && stack.state.length > 0) {
        entry.statePreview = stack.state.slice(0, 4).map((value) => Number(value).toFixed(5));
      }
      if (stack.regressors) {
        const keys = Object.keys(stack.regressors).sort((a, b) => Number(a) - Number(b));
        entry.regressorCount = keys.length;
        const firstKey = keys[0];
        if (firstKey !== undefined) {
          const reg = stack.regressors[firstKey];
          entry.sampleRegressor = {
            index: Number(firstKey),
            bias: typeof reg.bias === 'number' ? Number(reg.bias).toFixed(5) : reg.bias,
            weights: Array.isArray(reg.weights)
              ? reg.weights.slice(0, 5).map((value) => Number(value).toFixed(5))
              : [],
          };
        }
      } else {
        entry.regressorCount = 0;
        entry.note = 'Replay training to persist regression matrices.';
      }
      summary[name] = entry;
    }
    return formatJson(summary);
  };

  const fetchConfig = async () => {
    const response = await fetch(`data/config.json?cache=${Date.now()}`);
    if (!response.ok) {
      throw new Error(`Unable to load configuration (HTTP ${response.status}).`);
    }
    return response.json();
  };

  const refreshConfig = async () => {
    try {
      setStatus(configStatus, 'Loading configuration…');
      const config = await fetchConfig();
      configOutput.textContent = buildConfigPreview(config);
      setStatus(configStatus, `Last pulled: ${new Date().toLocaleTimeString()}`);
    } catch (error) {
      setStatus(configStatus, error.message, 'error');
      configOutput.textContent = error.stack ?? error.message;
    }
  };

  const loadSamples = async () => {
    try {
      const response = await fetch(`data/sample_payloads.json?cache=${Date.now()}`);
      if (!response.ok) {
        throw new Error(`Unable to load sample payloads (HTTP ${response.status}).`);
      }
      samples = await response.json();
      if (respondSampleEl && samples?.respondRequest?.body) {
        respondSampleEl.textContent = formatJson(samples.respondRequest.body);
      }
      if (trainSampleEl && samples?.trainRequest?.body) {
        trainSampleEl.textContent = formatJson(samples.trainRequest.body);
      }
      if (respondBtn) {
        respondBtn.disabled = false;
      }
      if (trainBtn) {
        trainBtn.disabled = false;
      }
      setStatus(sampleStatus, 'Sample payloads ready.');
      setStatus(respondStatus, 'Ready to send sample.');
      setStatus(trainStatus, 'Ready to replay batch.');
    } catch (error) {
      setStatus(sampleStatus, error.message, 'error');
      setStatus(respondStatus, 'Samples unavailable', 'error');
      setStatus(trainStatus, 'Samples unavailable', 'error');
      if (respondBtn) {
        respondBtn.disabled = true;
      }
      if (trainBtn) {
        trainBtn.disabled = true;
      }
    }
  };

  const runRespondCheck = async () => {
    if (!samples?.respondRequest?.body) {
      return;
    }
    try {
      respondBtn.disabled = true;
      setStatus(respondStatus, 'Contacting /api/respond.php…');
      respondOutput.textContent = 'Awaiting response from the server…';
      const response = await fetch('api/respond.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(samples.respondRequest.body),
      });
      if (!response.ok) {
        throw new Error(`Endpoint returned HTTP ${response.status}.`);
      }
      const payload = await response.json();
      const combined = [
        'Request payload:',
        formatJson(samples.respondRequest.body),
        '',
        'Server response:',
        formatJson(payload),
      ].join('\n');
      respondOutput.textContent = combined;
      setStatus(respondStatus, 'Response received.');
    } catch (error) {
      setStatus(respondStatus, error.message, 'error');
      respondOutput.textContent = error.stack ?? error.message;
    } finally {
      respondBtn.disabled = false;
    }
  };

  const runTrainingCheck = async () => {
    if (!samples?.trainRequest?.body) {
      return;
    }
    try {
      trainBtn.disabled = true;
      setStatus(trainStatus, 'Submitting to /api/train.php…');
      trainOutput.textContent = 'Awaiting training acknowledgement…';
      let beforeConfig = null;
      try {
        beforeConfig = await fetchConfig();
      } catch (error) {
        beforeConfig = null;
      }
      const response = await fetch('api/train.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(samples.trainRequest.body),
      });
      if (!response.ok) {
        throw new Error(`Endpoint returned HTTP ${response.status}.`);
      }
      const payload = await response.json();
      let afterConfig = null;
      try {
        afterConfig = await fetchConfig();
      } catch (error) {
        afterConfig = null;
      }
      const parts = [
        'Training request:',
        formatJson(samples.trainRequest.body),
        '',
        'Server acknowledgement:',
        formatJson(payload),
      ];
      if (beforeConfig && afterConfig) {
        parts.push('', 'Language stack delta preview:', summariseDelta(beforeConfig, afterConfig));
      } else if (afterConfig) {
        parts.push('', 'Latest configuration snapshot:', buildConfigPreview(afterConfig));
      }
      trainOutput.textContent = parts.join('\n');
      const trainedCount = typeof payload.trained === 'number' ? payload.trained : 'unknown';
      setStatus(trainStatus, `Training completed (${trainedCount} sample(s)).`);
    } catch (error) {
      setStatus(trainStatus, error.message, 'error');
      trainOutput.textContent = error.stack ?? error.message;
    } finally {
      trainBtn.disabled = false;
    }
  };

  if (respondBtn) {
    respondBtn.addEventListener('click', runRespondCheck);
  }
  if (trainBtn) {
    trainBtn.addEventListener('click', runTrainingCheck);
  }
  if (refreshConfigBtn) {
    refreshConfigBtn.addEventListener('click', refreshConfig);
  }

  loadSamples().then(() => {
    if (refreshConfigBtn) {
      refreshConfig();
    }
  });
})();
