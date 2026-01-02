#!/usr/bin/env python3
"""
Sentinel Chat Platform - Simple AI Chatbot Bot
A Python bot that connects to the chat platform and responds to messages.

This bot:
- Connects to the WebSocket server to receive messages
- Monitors chat rooms for messages directed at it
- Generates AI responses using a simple rule-based system (extendable to OpenAI/other APIs)
- Sends responses via the PHP API

Usage:
    python chatbot-bot.py

Configuration:
    Set environment variables or edit the CONFIG section below.
"""

import asyncio
import websockets
import json
import requests
import os
import sys
import time
import re
from urllib.parse import quote
from typing import Optional, Dict, List
import logging

# Configure logging
# Ensure logs directory exists
log_dir = 'logs'
if not os.path.exists(log_dir):
    os.makedirs(log_dir, exist_ok=True)

log_file = os.path.join(log_dir, 'chatbot-bot.log')
logging.basicConfig(
    level=logging.INFO,
    format='[%(asctime)s] %(levelname)s: %(message)s',
    handlers=[
        logging.FileHandler(log_file),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

# Configuration
CONFIG = {
    'bot_handle': os.getenv('BOT_HANDLE', 'ChatBot'),
    'bot_display_name': os.getenv('BOT_DISPLAY_NAME', 'ChatBot'),
    'bot_email': os.getenv('BOT_EMAIL', 'chatbot@sentinel.local'),
    'ws_host': os.getenv('WS_HOST', 'localhost'),
    'ws_port': int(os.getenv('WS_PORT', '8420')),
    'api_base_url': os.getenv('API_BASE_URL', 'http://localhost/iChat/api'),
    'api_secret': os.getenv('API_SECRET', 'change-me-now'),  # Should match your API secret
    'default_room': os.getenv('DEFAULT_ROOM', 'lobby'),
    'response_delay': float(os.getenv('RESPONSE_DELAY', '2.0')),  # Seconds to wait before responding
    'ai_provider': os.getenv('AI_PROVIDER', 'simple'),  # 'simple', 'openai', 'ollama'
    'openai_api_key': os.getenv('OPENAI_API_KEY', ''),
    'ollama_url': os.getenv('OLLAMA_URL', 'http://localhost:11434'),
    'ollama_model': os.getenv('OLLAMA_MODEL', 'llama2'),
}

# Bot personality and responses
BOT_RESPONSES = {
    'greetings': [
        "Hello! How can I help you today?",
        "Hi there! What's on your mind?",
        "Hey! Nice to chat with you!",
        "Hello! I'm here to chat. What would you like to talk about?",
    ],
    'questions': [
        "That's an interesting question! Let me think...",
        "Hmm, I'm not entirely sure, but I'd say...",
        "That's a good question! From what I understand...",
    ],
    'goodbye': [
        "Goodbye! Have a great day!",
        "See you later!",
        "Bye! It was nice chatting!",
        "Farewell! Take care!",
    ],
    'thanks': [
        "You're welcome!",
        "Happy to help!",
        "No problem at all!",
        "Anytime!",
    ],
    'default': [
        "That's interesting! Tell me more.",
        "I see. What else would you like to discuss?",
        "Hmm, I'm not sure how to respond to that, but I'm listening!",
        "That's a new one! Can you elaborate?",
    ],
}

class ChatBot:
    """Simple AI Chatbot for Sentinel Chat Platform"""
    
    def __init__(self, config: Dict):
        self.config = config
        self.bot_handle = config['bot_handle']
        self.ws_url = f"ws://{config['ws_host']}:{config['ws_port']}"
        self.api_base = config['api_base_url']
        self.api_secret = config['api_secret']
        self.default_room = config['default_room']
        self.ws = None
        self.connected = False
        self.current_room = config['default_room']
        self.conversation_history = {}  # room_id -> list of recent messages
        
    async def ensure_bot_user_exists(self) -> bool:
        """Ensure the bot user exists in the database"""
        try:
            # Try to get bot user via API (if endpoint exists)
            # For now, we'll assume the bot user is created manually or via a script
            logger.info(f"Bot user should exist as: {self.bot_handle}")
            return True
        except Exception as e:
            logger.error(f"Error checking bot user: {e}")
            return False
    
    async def connect_websocket(self) -> bool:
        """Connect to WebSocket server"""
        try:
            # Build WebSocket URL with authentication
            ws_url = f"{self.ws_url}?user_handle={quote(self.bot_handle)}&api_secret={quote(self.api_secret)}&room_id={quote(self.current_room)}"
            
            logger.info(f"Connecting to WebSocket server: {self.ws_url}")
            logger.info(f"Bot handle: {self.bot_handle}, Room: {self.current_room}")
            
            self.ws = await websockets.connect(ws_url)
            self.connected = True
            logger.info("WebSocket connected successfully!")
            
            # Send initial presence update
            await self.ws.send(json.dumps({
                'type': 'presence_update',
                'status': 'online'
            }))
            
            return True
        except Exception as e:
            logger.error(f"Failed to connect to WebSocket: {e}")
            return False
    
    async def send_message(self, room_id: str, message_text: str) -> bool:
        """Send a message via the PHP API"""
        try:
            # Encode message (base64 of rawurlencoded text - matches PHP's encoding)
            import base64
            from urllib.parse import quote
            # PHP uses: base64_encode(rawurlencode($message))
            urlencoded = quote(message_text, safe='')
            encoded_message = base64.b64encode(urlencoded.encode('utf-8')).decode('utf-8')
            
            # Prepare message data
            message_data = {
                'room_id': room_id,
                'sender_handle': self.bot_handle,
                'cipher_blob': encoded_message,
                'filter_version': 1
            }
            
            # Send via API (POST method, no action parameter needed)
            api_url = f"{self.api_base}/messages.php"
            headers = {
                'Content-Type': 'application/json',
                'X-API-SECRET': self.api_secret
            }
            
            response = requests.post(
                api_url,
                json=message_data,
                headers=headers,
                timeout=10
            )
            
            if response.status_code == 200:
                result = response.json()
                if result.get('success'):
                    logger.info(f"Message sent to {room_id}: {message_text[:50]}...")
                    return True
                else:
                    logger.error(f"API returned error: {result.get('error')}")
                    return False
            else:
                logger.error(f"API request failed with status {response.status_code}: {response.text}")
                return False
                
        except Exception as e:
            logger.error(f"Error sending message: {e}")
            return False
    
    def generate_response(self, message_text: str, room_id: str) -> Optional[str]:
        """Generate a response to a message using the configured AI provider"""
        message_lower = message_text.lower().strip()
        
        # Add to conversation history
        if room_id not in self.conversation_history:
            self.conversation_history[room_id] = []
        self.conversation_history[room_id].append({
            'text': message_text,
            'timestamp': time.time()
        })
        
        # Keep only last 10 messages per room
        if len(self.conversation_history[room_id]) > 10:
            self.conversation_history[room_id] = self.conversation_history[room_id][-10:]
        
        # Check if message is directed at the bot
        bot_mentioned = (
            self.bot_handle.lower() in message_lower or
            '@' + self.bot_handle.lower() in message_lower or
            message_lower.startswith('bot') or
            message_lower.startswith('hey bot') or
            message_lower.startswith('hi bot')
        )
        
        # If not mentioned, only respond occasionally (10% chance) to keep conversation natural
        if not bot_mentioned:
            import random
            if random.random() > 0.1:  # 90% chance to ignore
                return None
        
        # Generate response based on AI provider
        if self.config['ai_provider'] == 'openai' and self.config['openai_api_key']:
            return self._generate_openai_response(message_text, room_id)
        elif self.config['ai_provider'] == 'ollama':
            return self._generate_ollama_response(message_text, room_id)
        else:
            return self._generate_simple_response(message_text, room_id)
    
    def _generate_simple_response(self, message_text: str, room_id: str) -> str:
        """Simple rule-based response generation"""
        message_lower = message_text.lower().strip()
        
        # Greetings
        if any(word in message_lower for word in ['hello', 'hi', 'hey', 'greetings']):
            import random
            return random.choice(BOT_RESPONSES['greetings'])
        
        # Questions
        if '?' in message_text:
            import random
            base = random.choice(BOT_RESPONSES['questions'])
            # Try to answer simple questions
            if 'how are you' in message_lower:
                return "I'm doing great, thanks for asking! How are you?"
            elif 'what' in message_lower and 'your name' in message_lower:
                return f"I'm {self.bot_handle}! Nice to meet you!"
            elif 'who are you' in message_lower:
                return f"I'm {self.bot_handle}, a friendly AI chatbot here to chat with you!"
            else:
                return base
        
        # Goodbye
        if any(word in message_lower for word in ['bye', 'goodbye', 'see you', 'farewell']):
            import random
            return random.choice(BOT_RESPONSES['goodbye'])
        
        # Thanks
        if any(word in message_lower for word in ['thank', 'thanks', 'thx']):
            import random
            return random.choice(BOT_RESPONSES['thanks'])
        
        # Default response
        import random
        return random.choice(BOT_RESPONSES['default'])
    
    def _generate_openai_response(self, message_text: str, room_id: str) -> Optional[str]:
        """Generate response using OpenAI API"""
        try:
            import openai
            openai.api_key = self.config['openai_api_key']
            
            # Get recent conversation context
            context = ""
            if room_id in self.conversation_history:
                recent = self.conversation_history[room_id][-5:]  # Last 5 messages
                context = "\n".join([f"User: {msg['text']}" for msg in recent])
            
            response = openai.ChatCompletion.create(
                model="gpt-3.5-turbo",
                messages=[
                    {"role": "system", "content": f"You are {self.bot_handle}, a friendly and helpful chatbot in a chat room. Keep responses concise and conversational."},
                    {"role": "user", "content": message_text}
                ],
                max_tokens=150,
                temperature=0.7
            )
            
            return response.choices[0].message.content.strip()
        except Exception as e:
            logger.error(f"OpenAI API error: {e}")
            # Fallback to simple response
            return self._generate_simple_response(message_text, room_id)
    
    def _generate_ollama_response(self, message_text: str, room_id: str) -> Optional[str]:
        """Generate response using Ollama (local LLM)"""
        try:
            ollama_url = f"{self.config['ollama_url']}/api/generate"
            response = requests.post(
                ollama_url,
                json={
                    'model': self.config['ollama_model'],
                    'prompt': f"You are {self.bot_handle}, a friendly chatbot. Respond to: {message_text}",
                    'stream': False
                },
                timeout=30
            )
            
            if response.status_code == 200:
                result = response.json()
                return result.get('response', '').strip()
            else:
                logger.error(f"Ollama API error: {response.status_code}")
                return self._generate_simple_response(message_text, room_id)
        except Exception as e:
            logger.error(f"Ollama API error: {e}")
            return self._generate_simple_response(message_text, room_id)
    
    async def handle_message(self, message_data: Dict):
        """Handle incoming WebSocket message"""
        try:
            message_type = message_data.get('type')
            
            if message_type == 'new_message':
                message = message_data.get('message', {})
                room_id = message.get('room_id')
                sender_handle = message.get('sender_handle')
                cipher_blob = message.get('cipher_blob')
                
                # Don't respond to own messages
                if sender_handle == self.bot_handle:
                    return
                
                # Decode message (PHP uses: base64_encode(rawurlencode($message)))
                try:
                    import base64
                    from urllib.parse import unquote
                    decoded_b64 = base64.b64decode(cipher_blob).decode('utf-8')
                    message_text = unquote(decoded_b64)
                except Exception as e:
                    logger.error(f"Error decoding message: {e}")
                    return
                
                logger.info(f"Received message in {room_id} from {sender_handle}: {message_text[:50]}...")
                
                # Generate response
                response_text = self.generate_response(message_text, room_id)
                
                if response_text:
                    # Wait a bit before responding (more natural)
                    await asyncio.sleep(self.config['response_delay'])
                    
                    # Send response
                    await self.send_message(room_id, response_text)
            
            elif message_type == 'room_joined':
                room_id = message_data.get('room_id')
                self.current_room = room_id
                logger.info(f"Joined room: {room_id}")
            
            elif message_type == 'pong':
                # Respond to ping
                pass
            
            elif message_type == 'error':
                error_msg = message_data.get('message', 'Unknown error')
                logger.error(f"WebSocket error: {error_msg}")
            
        except Exception as e:
            logger.error(f"Error handling message: {e}")
    
    async def listen_for_messages(self):
        """Listen for messages from WebSocket"""
        try:
            async for message in self.ws:
                try:
                    data = json.loads(message)
                    await self.handle_message(data)
                except json.JSONDecodeError:
                    logger.warning(f"Received non-JSON message: {message[:100]}")
                except Exception as e:
                    logger.error(f"Error processing message: {e}")
        except websockets.exceptions.ConnectionClosed:
            logger.warning("WebSocket connection closed")
            self.connected = False
        except Exception as e:
            logger.error(f"WebSocket error: {e}")
            self.connected = False
    
    async def keepalive(self):
        """Send periodic ping to keep connection alive"""
        while self.connected:
            await asyncio.sleep(30)  # Ping every 30 seconds
            if self.connected and self.ws:
                try:
                    await self.ws.send(json.dumps({'type': 'ping'}))
                except Exception as e:
                    logger.error(f"Error sending ping: {e}")
                    self.connected = False
    
    async def run(self):
        """Main bot loop"""
        logger.info(f"Starting {self.bot_handle} bot...")
        logger.info(f"AI Provider: {self.config['ai_provider']}")
        logger.info(f"Default Room: {self.default_room}")
        
        # Ensure bot user exists
        await self.ensure_bot_user_exists()
        
        # Connect to WebSocket
        if not await self.connect_websocket():
            logger.error("Failed to connect. Exiting.")
            return
        
        # Start keepalive task
        keepalive_task = asyncio.create_task(self.keepalive())
        
        # Listen for messages
        try:
            await self.listen_for_messages()
        finally:
            keepalive_task.cancel()
            if self.ws:
                await self.ws.close()

async def main():
    """Main entry point"""
    # Ensure logs directory exists
    os.makedirs('logs', exist_ok=True)
    
    bot = ChatBot(CONFIG)
    
    # Run with auto-reconnect
    while True:
        try:
            await bot.run()
        except KeyboardInterrupt:
            logger.info("Bot stopped by user")
            break
        except Exception as e:
            logger.error(f"Bot error: {e}")
            logger.info("Reconnecting in 5 seconds...")
            await asyncio.sleep(5)
            bot.connected = False

if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        logger.info("Bot shutdown complete")

