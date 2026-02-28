import { useQuery, useMutation } from "@tanstack/react-query";
import { apiRequest, queryClient } from "@/lib/queryClient";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { useToast } from "@/hooks/use-toast";
import { Skeleton } from "@/components/ui/skeleton";
import type { Snippet } from "@shared/schema";
import { useLocation } from "wouter";
import {
  Code2,
  Trash2,
  Clock,
  FileCode,
  Loader2,
  BookOpen,
} from "lucide-react";
import { formatDistanceToNow } from "date-fns";

export default function Snippets() {
  const { toast } = useToast();
  const [, setLocation] = useLocation();

  const { data: snippets, isLoading } = useQuery<Snippet[]>({
    queryKey: ["/api/snippets"],
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await apiRequest("DELETE", `/api/snippets/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["/api/snippets"] });
      toast({ title: "Snippet deleted" });
    },
    onError: (error: Error) => {
      toast({ title: "Delete failed", description: error.message, variant: "destructive" });
    },
  });

  const handleLoad = (snippet: Snippet) => {
    sessionStorage.setItem("loadSnippet", JSON.stringify(snippet));
    setLocation("/");
  };

  if (isLoading) {
    return (
      <div className="p-6 max-w-5xl mx-auto" data-testid="status-loading-snippets">
        <div className="flex items-center gap-2 mb-6">
          <BookOpen className="w-5 h-5 text-primary" />
          <h1 className="text-xl font-semibold">Snippet Library</h1>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => (
            <Card key={i}>
              <CardContent className="p-4 space-y-3">
                <Skeleton className="h-5 w-3/4" />
                <Skeleton className="h-20 w-full" />
                <Skeleton className="h-4 w-1/2" />
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-5xl mx-auto" data-testid="snippets-page">
      <div className="flex items-center gap-2 mb-6">
        <BookOpen className="w-5 h-5 text-primary" />
        <h1 className="text-xl font-semibold">Snippet Library</h1>
        <span className="text-sm text-muted-foreground ml-2" data-testid="text-snippet-count">
          {snippets?.length || 0} {(snippets?.length || 0) === 1 ? "snippet" : "snippets"}
        </span>
      </div>

      {!snippets || snippets.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20 text-muted-foreground" data-testid="status-empty-snippets">
          <FileCode className="w-16 h-16 opacity-20 mb-4" />
          <p className="text-lg font-medium mb-1">No snippets yet</p>
          <p className="text-sm">Save code from the playground to build your library.</p>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {snippets.map((snippet) => (
            <Card key={snippet.id} className="group" data-testid={`card-snippet-${snippet.id}`}>
              <CardContent className="p-4 flex flex-col gap-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-center gap-2 min-w-0">
                    <Code2 className="w-4 h-4 text-primary shrink-0" />
                    <h3
                      className="font-medium text-sm truncate"
                      data-testid={`text-snippet-title-${snippet.id}`}
                    >
                      {snippet.title}
                    </h3>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity"
                    onClick={(e) => {
                      e.stopPropagation();
                      deleteMutation.mutate(snippet.id);
                    }}
                    disabled={deleteMutation.isPending}
                    data-testid={`button-delete-snippet-${snippet.id}`}
                  >
                    {deleteMutation.isPending ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    ) : (
                      <Trash2 className="w-3.5 h-3.5" />
                    )}
                  </Button>
                </div>

                {snippet.description && (
                  <p className="text-xs text-muted-foreground line-clamp-2">
                    {snippet.description}
                  </p>
                )}

                <div className="bg-muted/40 rounded-md p-2.5 overflow-hidden">
                  <pre className="text-xs font-mono text-muted-foreground line-clamp-4 whitespace-pre-wrap">
                    {snippet.code}
                  </pre>
                </div>

                <div className="flex items-center justify-between gap-2 pt-1">
                  <span className="flex items-center gap-1 text-xs text-muted-foreground">
                    <Clock className="w-3 h-3" />
                    {formatDistanceToNow(new Date(snippet.createdAt), { addSuffix: true })}
                  </span>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => handleLoad(snippet)}
                    data-testid={`button-load-snippet-${snippet.id}`}
                  >
                    Open
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
