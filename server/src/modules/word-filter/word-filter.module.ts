/**
 * Sentinel Chat Platform - Word Filter Module
 * 
 * Handles message filtering and profanity detection.
 */

import { Module } from '@nestjs/common';
import { WordFilterController } from './word-filter.controller';
import { WordFilterService } from './word-filter.service';

@Module({
  controllers: [WordFilterController],
  providers: [WordFilterService],
})
export class WordFilterModule {}

