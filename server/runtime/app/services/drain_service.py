"""
Sentinel Chat Platform - Message Draining Service

Business logic for draining messages from temporary outbox
and delivering them to the primary NestJS server.
"""

import httpx
from sqlalchemy.orm import Session
from app.models.outbox import TempOutbox
from app.config import settings
from datetime import datetime


class DrainService:
    """
    Service for draining messages from temporary outbox.
    
    Retrieves pending messages, attempts to deliver them to the
    primary server, and marks them as delivered on success.
    """
    
    def __init__(self, db: Session):
        self.db = db
        self.primary_server_url = settings.PRIMARY_SERVER_URL
        self.api_secret = settings.API_SECRET
    
    def drain_batch(self, batch_size: int) -> dict:
        """
        Drain a batch of pending messages.
        
        Args:
            batch_size: Maximum number of messages to process
            
        Returns:
            Dictionary with processing results
        """
        # Get pending messages
        pending_messages = (
            self.db.query(TempOutbox)
            .filter(
                TempOutbox.delivered_at.is_(None),
                TempOutbox.deleted_at.is_(None),
            )
            .order_by(TempOutbox.queued_at.asc())
            .limit(batch_size)
            .all()
        )
        
        if not pending_messages:
            return {
                "processed": 0,
                "delivered": 0,
                "failed": 0,
            }
        
        delivered_ids = []
        failed_ids = []
        
        # Attempt to deliver each message
        for message in pending_messages:
            try:
                if self._deliver_to_primary(message):
                    delivered_ids.append(message.id)
                else:
                    failed_ids.append(message.id)
            except Exception as e:
                # Log error but continue processing
                print(f"Error delivering message {message.id}: {e}")
                failed_ids.append(message.id)
        
        # Mark delivered messages
        if delivered_ids:
            (
                self.db.query(TempOutbox)
                .filter(TempOutbox.id.in_(delivered_ids))
                .update(
                    {"delivered_at": datetime.utcnow()},
                    synchronize_session=False,
                )
            )
            self.db.commit()
        
        return {
            "processed": len(pending_messages),
            "delivered": len(delivered_ids),
            "failed": len(failed_ids),
        }
    
    def _deliver_to_primary(self, message: TempOutbox) -> bool:
        """
        Deliver a single message to the primary server.
        
        Args:
            message: TempOutbox instance to deliver
            
        Returns:
            True if delivery succeeded, False otherwise
        """
        try:
            with httpx.Client(timeout=10.0) as client:
                response = client.post(
                    f"{self.primary_server_url}/api/messaging/rooms/{message.room_id}/messages",
                    json={
                        "sender_handle": message.sender_handle,
                        "cipher_blob": message.cipher_blob,
                        "filter_version": message.filter_version,
                    },
                    headers={
                        "X-API-SECRET": self.api_secret,
                        "Content-Type": "application/json",
                    },
                )
                
                return response.status_code == 200
        except Exception as e:
            print(f"Failed to deliver message {message.id}: {e}")
            return False

