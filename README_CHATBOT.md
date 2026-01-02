# Sentinel Chat Platform - AI Chatbot Bot

A simple Python chatbot that connects to the Sentinel Chat Platform and responds to messages in chat rooms.

## Features

- **WebSocket Integration**: Connects to the chat platform's WebSocket server
- **Multiple AI Providers**: Supports simple rule-based, OpenAI, or Ollama (local LLM)
- **Natural Conversations**: Responds to mentions and participates in conversations
- **Room Support**: Can join and chat in any room
- **Auto-Reconnect**: Automatically reconnects if connection is lost

## Installation

1. **Install Python dependencies:**
```bash
pip install -r requirements-bot.txt
```

2. **Configure the bot:**
   - Edit `chatbot-bot.py` and update the `CONFIG` dictionary, OR
   - Set environment variables (see Configuration section)

3. **Create bot user:**
   - The bot needs a user account in the database
   - You can create it manually via the admin panel or use a script
   - Bot handle should match `BOT_HANDLE` in config

## Configuration

### Environment Variables

```bash
# Bot identity
export BOT_HANDLE="ChatBot"
export BOT_DISPLAY_NAME="ChatBot"
export BOT_EMAIL="chatbot@sentinel.local"

# WebSocket connection
export WS_HOST="localhost"
export WS_PORT="8420"

# API configuration
export API_BASE_URL="http://localhost/iChat/api"
export API_SECRET="your-api-secret-here"  # Must match your API secret

# Bot behavior
export DEFAULT_ROOM="lobby"
export RESPONSE_DELAY="2.0"  # Seconds to wait before responding

# AI Provider (simple, openai, ollama)
export AI_PROVIDER="simple"

# OpenAI (if using OpenAI provider)
export OPENAI_API_KEY="sk-..."

# Ollama (if using Ollama provider)
export OLLAMA_URL="http://localhost:11434"
export OLLAMA_MODEL="llama2"
```

### Configuration in Code

Edit the `CONFIG` dictionary in `chatbot-bot.py`:

```python
CONFIG = {
    'bot_handle': 'ChatBot',
    'api_secret': 'change-me-now',
    'ai_provider': 'simple',  # or 'openai' or 'ollama'
    # ... etc
}
```

## Usage

### Run the bot:

```bash
python chatbot-bot.py
```

### Run in background (Linux/Mac):

```bash
nohup python chatbot-bot.py > logs/chatbot-bot.out 2>&1 &
```

### Run as Windows service:

Use a service manager like NSSM (Non-Sucking Service Manager) or Task Scheduler.

## AI Providers

### 1. Simple (Default)
- Rule-based responses
- No external dependencies
- Fast and lightweight
- Good for basic conversations

### 2. OpenAI
- Requires OpenAI API key
- More intelligent responses
- Set `AI_PROVIDER=openai` and `OPENAI_API_KEY=sk-...`

### 3. Ollama (Local LLM)
- Run locally, no API costs
- Requires Ollama installed and running
- Set `AI_PROVIDER=ollama` and configure `OLLAMA_URL` and `OLLAMA_MODEL`

## Bot Behavior

- **Mentions**: Responds when mentioned by name
- **Random**: Occasionally responds to general messages (10% chance)
- **Context**: Remembers last 10 messages per room for context
- **Delay**: Waits 2 seconds before responding (configurable)

## Logs

Logs are written to:
- `logs/chatbot-bot.log` - File log
- Console output - Real-time logging

## Troubleshooting

### Bot won't connect:
- Check WebSocket server is running (port 8420)
- Verify API secret matches your configuration
- Ensure bot user exists in database

### Bot not responding:
- Check bot has permission to send messages (RBAC)
- Verify bot user is not banned
- Check logs for errors

### OpenAI/Ollama errors:
- Verify API keys/URLs are correct
- Check network connectivity
- Bot will fallback to simple responses on error

## Security Notes

- Bot uses API secret for authentication
- Bot user should have appropriate RBAC permissions
- Consider limiting bot to specific rooms
- Monitor bot logs for suspicious activity

## Extending the Bot

### Add custom responses:

Edit `BOT_RESPONSES` dictionary in `chatbot-bot.py`:

```python
BOT_RESPONSES = {
    'custom': [
        "Custom response 1",
        "Custom response 2",
    ],
}
```

### Add custom AI provider:

1. Add provider name to `CONFIG['ai_provider']`
2. Add `_generate_<provider>_response()` method
3. Update `generate_response()` to call new method

## Example Conversation

```
User: Hello ChatBot!
Bot: Hello! How can I help you today?

User: What's your name?
Bot: I'm ChatBot! Nice to meet you!

User: How are you?
Bot: I'm doing great, thanks for asking! How are you?
```

## License

Part of the Sentinel Chat Platform.

