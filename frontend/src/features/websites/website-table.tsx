"use client";

import { useState } from "react";
import { Trash2 } from "lucide-react";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useDeleteWebsite } from "@/features/websites/hooks";
import type { Website } from "@/types/website";

export function WebsiteTable({ projectId, websites }: { projectId: number; websites: Website[] }) {
  const deleteWebsite = useDeleteWebsite(projectId);
  const [pendingId, setPendingId] = useState<number | null>(null);

  const handleDelete = (id: number) => {
    setPendingId(id);
    deleteWebsite.mutate(id, {
      onSettled: () => setPendingId(null),
    });
  };

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>サイト名</TableHead>
          <TableHead>URL</TableHead>
          <TableHead>種別</TableHead>
          <TableHead className="w-0" />
        </TableRow>
      </TableHeader>
      <TableBody>
        {websites.map((website) => (
          <TableRow key={website.id}>
            <TableCell className="font-medium">{website.name}</TableCell>
            <TableCell className="max-w-xs truncate text-muted-foreground">{website.normalized_url}</TableCell>
            <TableCell>
              {website.is_primary ? <Badge>自社サイト</Badge> : <Badge variant="outline">競合サイト</Badge>}
            </TableCell>
            <TableCell>
              <AlertDialog>
                <AlertDialogTrigger
                  render={<Button variant="ghost" size="icon" aria-label="削除" disabled={pendingId === website.id} />}
                >
                  <Trash2 className="size-4" />
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>このサイトを削除しますか？</AlertDialogTitle>
                    <AlertDialogDescription>
                      「{website.name}」を削除します。この操作は元に戻せません。
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>キャンセル</AlertDialogCancel>
                    <AlertDialogAction
                      className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                      onClick={() => handleDelete(website.id)}
                    >
                      削除する
                    </AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
