#!/bin/bash

echo "🚀 Initializing project..."

if [ ! -f .env ]; then
    echo "📝 Creating .env from .env.example"
    cp .env.example .env
    echo "✅ .env created! Please edit it with your settings."
else
    echo "⚠️  .env already exists, skipping"
fi

echo ""
echo "✅ Done!"
echo ""
echo "Next steps:"
echo "1. Edit .env with your settings"
echo "2. Run: ./setup.sh"