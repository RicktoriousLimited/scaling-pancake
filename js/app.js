const form = document.getElementById('chat-form');
const input = document.getElementById('chat-input');
const messages = document.getElementById('messages');
const stackVisuals = document.getElementById('stack-visuals');

const conversationKey = 'msncb-conversation-id';
const createConversationId = () => {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return `conv-${Date.now()}-${Math.random().toString(16).slice(2)}`;
};

let conversationId = localStorage.getItem(conversationKey) || createConversationId();
localStorage.setItem(conversationKey, conversationId);

const renderMessage = (text, type) => {
  const container = document.createElement('div');
  container.className = `message ${type}`;
  container.textContent = text;
  messages.appendChild(container);
  messages.scrollTop = messages.scrollHeight;
};

const renderStackOutputs = (outputs) => {
  stackVisuals.innerHTML = '';
  Object.entries(outputs).forEach(([stack, values]) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'stack-output';

    const title = document.createElement('h3');
    title.textContent = stack.toUpperCase();
    wrapper.appendChild(title);

    values.forEach((value, idx) => {
      const bar = document.createElement('div');
      bar.className = 'bar';
      bar.style.width = `${Math.min(Math.abs(value) * 100, 100)}%`;
      bar.style.background = value >= 0 ? '#2d8bff' : '#ff6584';
      bar.title = value.toFixed(3);
      wrapper.appendChild(bar);
    });

    stackVisuals.appendChild(wrapper);
  });
};

const resetButton = document.getElementById('reset-conversation');
if (resetButton) {
  resetButton.addEventListener('click', () => {
    conversationId = createConversationId();
    localStorage.setItem(conversationKey, conversationId);
    messages.innerHTML = '';
    stackVisuals.innerHTML = '';
    renderMessage('Conversation memory reset. Starting fresh.', 'bot');
  });
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const text = input.value.trim();
  if (!text) return;

  renderMessage(text, 'user');
  input.value = '';

  try {
    const response = await fetch('api/respond.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, conversationId })
    });
    const data = await response.json();
    if (response.ok) {
      if (data.conversationId) {
        conversationId = data.conversationId;
        localStorage.setItem(conversationKey, conversationId);
      }
      renderMessage(data.message, 'bot');
      renderStackOutputs(data.outputs);
    } else {
      renderMessage(`Error: ${data.error}`, 'bot');
    }
  } catch (error) {
    renderMessage(`Request failed: ${error}`, 'bot');
  }
});
