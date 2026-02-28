import { useState, useRef, useEffect } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { apiRequest, queryClient } from "@/lib/queryClient";
import { Button } from "@/components/ui/button";
import { useToast } from "@/hooks/use-toast";
import type { Snippet } from "@shared/schema";
import {
  Play,
  Save,
  Trash2,
  Terminal,
  Code2,
  Loader2,
  Clock,
  Copy,
  Check,
  RotateCcw,
} from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const DEFAULT_CODE = `<?php

echo "Hello, PHP Playground!\\n\\n";

// Variables and types
$name = "World";
$version = phpversion();
echo "PHP Version: $version\\n";
echo "Welcome, $name!\\n\\n";

// Array operations
$fruits = ["Apple", "Banana", "Cherry", "Date"];
echo "Fruits: " . implode(", ", $fruits) . "\\n";
echo "Count: " . count($fruits) . "\\n\\n";

// Simple loop
for ($i = 1; $i <= 5; $i++) {
    echo "Iteration $i: " . str_repeat("*", $i) . "\\n";
}
`;

export default function Playground() {
  const [code, setCode] = useState(DEFAULT_CODE);
  const [output, setOutput] = useState("");
  const [executionTime, setExecutionTime] = useState<number | null>(null);
  const [copied, setCopied] = useState(false);
  const [saveDialogOpen, setSaveDialogOpen] = useState(false);
  const [saveTitle, setSaveTitle] = useState("");
  const [saveDescription, setSaveDescription] = useState("");
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const { toast } = useToast();

  const runMutation = useMutation({
    mutationFn: async (phpCode: string) => {
      const res = await apiRequest("POST", "/api/execute", { code: phpCode });
      return res.json();
    },
    onSuccess: (data) => {
      setOutput(data.output || "");
      setExecutionTime(data.executionTime || null);
    },
    onError: (error: Error) => {
      setOutput(`Error: ${error.message}`);
      setExecutionTime(null);
    },
  });

  const saveMutation = useMutation({
    mutationFn: async (snippet: {
      title: string;
      code: string;
      description: string;
    }) => {
      const res = await apiRequest("POST", "/api/snippets", snippet);
      return res.json();
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["/api/snippets"] });
      setSaveDialogOpen(false);
      setSaveTitle("");
      setSaveDescription("");
      toast({ title: "Snippet saved", description: "Your code has been saved to the library." });
    },
    onError: (error: Error) => {
      toast({ title: "Save failed", description: error.message, variant: "destructive" });
    },
  });

  const handleRun = () => {
    runMutation.mutate(code);
  };

  const handleSave = () => {
    if (!saveTitle.trim()) return;
    saveMutation.mutate({
      title: saveTitle.trim(),
      code,
      description: saveDescription.trim(),
    });
  };

  const handleCopy = async () => {
    await navigator.clipboard.writeText(code);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleClear = () => {
    setCode("");
    setOutput("");
    setExecutionTime(null);
    textareaRef.current?.focus();
  };

  const handleReset = () => {
    setCode(DEFAULT_CODE);
    setOutput("");
    setExecutionTime(null);
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key === "Enter") {
      e.preventDefault();
      handleRun();
    }
    if (e.key === "Tab") {
      e.preventDefault();
      const target = e.target as HTMLTextAreaElement;
      const start = target.selectionStart;
      const end = target.selectionEnd;
      const newCode = code.substring(0, start) + "    " + code.substring(end);
      setCode(newCode);
      setTimeout(() => {
        target.selectionStart = target.selectionEnd = start + 4;
      }, 0);
    }
  };

  useEffect(() => {
    const stored = sessionStorage.getItem("loadSnippet");
    if (stored) {
      try {
        const snippet = JSON.parse(stored);
        setCode(snippet.code);
        setOutput("");
        setExecutionTime(null);
      } catch {}
      sessionStorage.removeItem("loadSnippet");
    }
  }, []);

  const lineCount = code.split("\n").length;

  return (
    <div className="flex flex-col h-full" data-testid="playground-page">
      <div className="flex items-center justify-between gap-2 px-4 py-2 border-b bg-card/50">
        <div className="flex items-center gap-2 flex-wrap">
          <Button
            onClick={handleRun}
            disabled={runMutation.isPending || !code.trim()}
            data-testid="button-run"
          >
            {runMutation.isPending ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Play className="w-4 h-4" />
            )}
            {runMutation.isPending ? "Running..." : "Run"}
          </Button>

          <Dialog open={saveDialogOpen} onOpenChange={setSaveDialogOpen}>
            <DialogTrigger asChild>
              <Button variant="secondary" disabled={!code.trim()} data-testid="button-save-trigger">
                <Save className="w-4 h-4" />
                Save
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Save Snippet</DialogTitle>
              </DialogHeader>
              <div className="flex flex-col gap-3 pt-2">
                <Input
                  placeholder="Snippet title"
                  value={saveTitle}
                  onChange={(e) => setSaveTitle(e.target.value)}
                  data-testid="input-save-title"
                />
                <Textarea
                  placeholder="Description (optional)"
                  value={saveDescription}
                  onChange={(e) => setSaveDescription(e.target.value)}
                  className="resize-none"
                  rows={3}
                  data-testid="input-save-description"
                />
                <Button
                  onClick={handleSave}
                  disabled={!saveTitle.trim() || saveMutation.isPending}
                  data-testid="button-save-confirm"
                >
                  {saveMutation.isPending ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : (
                    <Save className="w-4 h-4" />
                  )}
                  Save Snippet
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>

        <div className="flex items-center gap-1 flex-wrap">
          <Button variant="ghost" size="icon" onClick={handleCopy} data-testid="button-copy">
            {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
          </Button>
          <Button variant="ghost" size="icon" onClick={handleReset} data-testid="button-reset">
            <RotateCcw className="w-4 h-4" />
          </Button>
          <Button variant="ghost" size="icon" onClick={handleClear} data-testid="button-clear">
            <Trash2 className="w-4 h-4" />
          </Button>
        </div>
      </div>

      <div className="flex-1 flex flex-col lg:flex-row min-h-0">
        <div className="flex-1 flex flex-col min-h-0 border-b lg:border-b-0 lg:border-r">
          <div className="flex items-center gap-2 px-4 py-2 border-b bg-muted/30">
            <Code2 className="w-4 h-4 text-muted-foreground" />
            <span className="text-sm font-medium text-muted-foreground" data-testid="text-editor-label">
              Editor
            </span>
            <span className="text-xs text-muted-foreground ml-auto" data-testid="text-line-count">
              {lineCount} {lineCount === 1 ? "line" : "lines"}
            </span>
          </div>
          <div className="flex-1 flex min-h-0">
            <div className="py-3 px-2 bg-muted/20 text-right select-none overflow-hidden border-r">
              {Array.from({ length: lineCount }, (_, i) => (
                <div
                  key={i}
                  className="text-xs leading-[1.625rem] text-muted-foreground/60 font-mono px-1"
                >
                  {i + 1}
                </div>
              ))}
            </div>
            <textarea
              ref={textareaRef}
              value={code}
              onChange={(e) => setCode(e.target.value)}
              onKeyDown={handleKeyDown}
              className="flex-1 bg-transparent font-mono text-sm leading-[1.625rem] p-3 resize-none outline-none min-h-[200px] text-foreground placeholder:text-muted-foreground"
              spellCheck={false}
              autoCapitalize="off"
              autoCorrect="off"
              placeholder="<?php // Write your PHP code here..."
              data-testid="input-code-editor"
            />
          </div>
        </div>

        <div className="flex-1 flex flex-col min-h-0">
          <div className="flex items-center gap-2 px-4 py-2 border-b bg-muted/30">
            <Terminal className="w-4 h-4 text-muted-foreground" />
            <span className="text-sm font-medium text-muted-foreground" data-testid="text-output-label">
              Output
            </span>
            {executionTime !== null && (
              <span
                className="flex items-center gap-1 text-xs text-muted-foreground ml-auto"
                data-testid="text-execution-time"
              >
                <Clock className="w-3 h-3" />
                {executionTime}ms
              </span>
            )}
          </div>
          <div className="flex-1 overflow-auto p-4 min-h-[200px]">
            {runMutation.isPending ? (
              <div className="flex items-center gap-2 text-muted-foreground" data-testid="status-running">
                <Loader2 className="w-4 h-4 animate-spin" />
                <span className="text-sm">Executing PHP code...</span>
              </div>
            ) : output ? (
              <pre
                className="font-mono text-sm whitespace-pre-wrap break-words text-foreground"
                data-testid="text-output"
              >
                {output}
              </pre>
            ) : (
              <div className="flex flex-col items-center justify-center h-full text-muted-foreground gap-2" data-testid="status-empty-output">
                <Terminal className="w-8 h-8 opacity-30" />
                <p className="text-sm">
                  Press <kbd className="px-1.5 py-0.5 rounded-md bg-muted text-xs font-mono">Ctrl+Enter</kbd> to run
                </p>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
