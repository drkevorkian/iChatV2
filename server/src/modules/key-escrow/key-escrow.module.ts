/**
 * Sentinel Chat Platform - Key Escrow Module
 * 
 * Handles key escrow requests for security and compliance.
 */

import { Module } from '@nestjs/common';
import { KeyEscrowController } from './key-escrow.controller';
import { KeyEscrowService } from './key-escrow.service';

@Module({
  controllers: [KeyEscrowController],
  providers: [KeyEscrowService],
})
export class KeyEscrowModule {}

